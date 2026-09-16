<?php

namespace App\Filament\Concerns;

use App\Domain\Kimai\KimaiSynchronizer;
use App\Enums\SyncStatus;
use App\Filament\Pages\Preferensi;
use App\Jobs\SyncKimaiTimesheets;
use App\Models\SyncRun;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * F-12 — tombol sync di header Dashboard dan Daftar Lembur.
 *
 * Polling menempel pada tombolnya sendiri lewat wire:poll, bukan pada halaman:
 * atributnya hilang begitu run selesai, sehingga polling berhenti sendiri tanpa
 * ada yang perlu mematikannya. Filament Page tidak punya interval polling bawaan
 * (hanya Widget yang punya), dan ini menghindari render hook global yang akan
 * ikut jalan di setiap halaman panel.
 *
 * Polling itu hanya me-render ulang komponen HALAMAN. Tabel yang hidup di
 * komponen halaman (Daftar Lembur, Riwayat Sync) ikut segar dengan sendirinya,
 * tetapi widget dashboard adalah komponen Livewire terpisah dan Livewire 3 tidak
 * me-render anak saat induknya berubah. Karena itu selesainya run juga disiarkan
 * sebagai event `kimai-sync-selesai`; yang mendengarkannya ada di
 * RefreshesAfterKimaiSync.
 */
trait SyncsWithKimai
{
    /**
     * Run yang widget-nya sudah disegarkan di TAB INI. Sengaja properti komponen,
     * bukan session: session dibagi antar tab, dan kalau penanda dispatch ikut
     * dibagi, tab kedua akan menampilkan data basi selamanya. Sekaligus pengaman
     * kalau wire:poll gagal dicabut saat tombolnya berubah — tanpa ini satu poll
     * yatim akan menyegarkan lima widget setiap tiga detik, selamanya.
     */
    public ?int $kimaiRefreshedRun = null;

    public function kimaiSyncAction(): Action
    {
        $user = Auth::user();

        if ($user === null || ! $user->hasKimaiConnection()) {
            // F-12 — belum ada API key: yang tampil tautan ke pengaturannya.
            return Action::make('hubungkanKimai')
                ->label('Hubungkan Kimai')
                ->icon(Heroicon::OutlinedLink)
                ->color('gray')
                ->url(Preferensi::getUrl());
        }

        $running = $this->kimaiSyncIsRunning($user);

        return Action::make('syncKimai')
            ->label($running ? 'Menyinkronkan…' : 'Sync Kimai')
            ->icon($running ? Heroicon::OutlinedArrowPath : Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->disabled($running)
            // F-12 — tidak ada modal, overlay, atau blocker apa pun.
            ->requiresConfirmation(false)
            ->extraAttributes($running ? ['wire:poll.3s' => 'refreshKimaiSync'] : [])
            ->action(fn () => $this->startKimaiSync());
    }

    /** F-12 — respons < 300ms: hanya menaruh job ke queue, tidak menunggu Kimai. */
    public function startKimaiSync(): void
    {
        $user = Auth::user();

        if ($user === null || ! $user->hasKimaiConnection()) {
            return;
        }

        // SY-18 — permintaan baru ditolak selama masih ada job milik user ini.
        if ($this->kimaiSyncIsRunning($user)) {
            Notification::make()
                ->warning()
                ->title('Sync sedang berjalan')
                ->body('Tunggu sampai yang sekarang selesai.')
                ->send();

            return;
        }

        SyncKimaiTimesheets::markPending($user);
        SyncKimaiTimesheets::dispatch($user);

        // Run terakhir ditandai sudah dilihat, supaya notifikasi selesai nanti
        // benar-benar milik run yang baru ini.
        $this->rememberLastSeenRun($user);

        $this->rebuildKimaiSyncAction();

        Notification::make()
            ->success()
            ->title('Sync berjalan di latar belakang')
            ->body('Kamu bisa lanjut kerja.')
            ->send();
    }

    /**
     * Dipanggil wire:poll setiap 3 detik selama run hidup. Efeknya tiga: halaman
     * ter-render ulang, widget dashboard disuruh menyegarkan dirinya sendiri, dan
     * begitu run selesai ringkasannya muncul sebagai notifikasi.
     */
    public function refreshKimaiSync(): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        // Lebih luas daripada memeriksa status run: jendela "sudah di-dispatch,
        // worker belum mengambil" belum punya baris sync_runs sama sekali, dan di
        // jendela itu latestKimaiRun() masih menunjuk run LAMA yang sudah selesai.
        if ($this->kimaiSyncIsRunning($user)) {
            return;
        }

        $latest = $this->latestKimaiRun($user);

        if ($latest === null) {
            return;
        }

        // Menyegarkan widget didahulukan dan penandanya per komponen: notifikasi
        // cukup sekali untuk semua tab, tetapi setiap tab tetap wajib menyegarkan
        // widget-nya sendiri.
        if ($this->kimaiRefreshedRun !== $latest->id) {
            $this->kimaiRefreshedRun = $latest->id;
            $this->dispatch('kimai-sync-selesai');
        }

        if (session('kimai.last_seen_run') === $latest->id) {
            return;
        }

        session(['kimai.last_seen_run' => $latest->id]);

        // F-13 — ringkasan yang menyebut apa yang berubah, bukan sekadar "berhasil".
        Notification::make()
            ->status($latest->status === SyncStatus::Failed ? 'danger' : 'success')
            ->title($latest->summary())
            ->body('Detail lengkapnya ada di Riwayat Sync.')
            ->send();
    }

    /**
     * Filament menyusun header action SEKALI per request, lewat hook
     * cacheInteractsWithHeaderActions() yang jalan saat boot — jadi jauh sebelum
     * handler tombolnya dipanggil. Tanpa menyusun ulang di sini, render setelah
     * klik memakai objek Action lama yang masih dibangun dengan $running = false:
     * tombolnya tetap tertulis "Sync Kimai", tetap aktif, dan yang terpenting
     * TIDAK membawa wire:poll. Akibatnya refreshKimaiSync() tidak pernah
     * terpanggil sama sekali — tidak ada polling, tidak ada notifikasi selesai,
     * dan tidak ada sinyal ke widget.
     *
     * cacheAction() menyimpan berdasarkan nama, jadi menyusun ulang menimpa
     * entri lama alih-alih menggandakannya.
     */
    protected function rebuildKimaiSyncAction(): void
    {
        if (! method_exists($this, 'cacheInteractsWithHeaderActions')) {
            return;
        }

        $this->cachedHeaderActions = [];
        $this->cacheInteractsWithHeaderActions();
    }

    protected function kimaiSyncIsRunning(User $user): bool
    {
        // §10 — sebelum menilai, bersihkan run yang sudah kedaluwarsa. Tanpa ini,
        // satu job yang mati diam-diam mengunci tombol sync selamanya.
        KimaiSynchronizer::failStaleRuns($user);

        // Tiga penanda berbeda karena job punya tiga fase: sudah di-dispatch tapi
        // belum diambil worker, sedang memegang kunci, dan sudah menulis sync_run.
        return SyncKimaiTimesheets::isPendingFor($user)
            || SyncKimaiTimesheets::isRunningFor($user)
            || SyncRun::query()->where('user_id', $user->id)->active()->exists();
    }

    protected function latestKimaiRun(User $user): ?SyncRun
    {
        return SyncRun::query()->where('user_id', $user->id)->latest('id')->first();
    }

    /**
     * Run terakhir ditandai sudah dilihat DAN sudah disegarkan, supaya run lama
     * tidak diumumkan ulang oleh poll pertama dari run yang baru saja dimulai.
     */
    protected function rememberLastSeenRun(User $user): void
    {
        $id = $this->latestKimaiRun($user)?->id;

        session(['kimai.last_seen_run' => $id]);
        $this->kimaiRefreshedRun = $id;
    }
}
