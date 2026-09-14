<?php

namespace App\Filament\Concerns;

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
 */
trait SyncsWithKimai
{
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

        Notification::make()
            ->success()
            ->title('Sync berjalan di latar belakang')
            ->body('Kamu bisa lanjut kerja.')
            ->send();
    }

    /**
     * Dipanggil wire:poll setiap 3 detik selama run hidup. Efeknya dua: halaman
     * ter-render ulang (tabel dan stat tile ikut segar), dan begitu run selesai,
     * ringkasannya muncul sebagai notifikasi.
     */
    public function refreshKimaiSync(): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $latest = $this->latestKimaiRun($user);

        if ($latest === null || $latest->status->isActive()) {
            return;
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

    protected function kimaiSyncIsRunning(User $user): bool
    {
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

    protected function rememberLastSeenRun(User $user): void
    {
        session(['kimai.last_seen_run' => $this->latestKimaiRun($user)?->id]);
    }
}
