<?php

namespace App\Filament\Concerns;

use App\Domain\Timesheet\UploadDrafter;
use App\Domain\Timesheet\UploadRecovery;
use App\Enums\UploadEntryStatus;
use App\Enums\UploadStatus;
use App\Jobs\PostTimesheetUpload;
use App\Models\TimesheetUpload;
use App\Models\TimesheetUploadEntry;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Pratinjau, pengiriman, kemajuan, dan riwayat sebuah TimesheetUpload.
 *
 * Dipakai bersama oleh Upload Timesheet (entri dari workbook) dan Isi Timesheet
 * (entri dari form). Keduanya hanya berbeda di langkah 1 — dari mana draf lahir;
 * sejak draf ada di database, sisanya identik dan tidak boleh punya dua salinan.
 *
 * Halaman pemakainya menentukan tiga hal lewat hook di bagian bawah trait ini.
 */
trait PreviewsTimesheetUpload
{
    public ?int $uploadId = null;

    /**
     * Upload yang BARU SAJA selesai, untuk panel hasil yang menggantikan pratinjau.
     *
     * Sengaja terpisah dari $uploadId: pratinjaunya sudah dibuang (tabelnya tidak
     * bisa diapa-apakan lagi), tetapi layar tidak boleh mendadak kosong begitu saja.
     * Sama seperti $uploadId, yang disimpan cuma satu id — badan datanya tetap di
     * database. mount() sengaja TIDAK memulihkannya: panel ini kabar sesaat, dan
     * riwayatnya sudah punya tempat sendiri di seksi "Riwayat upload".
     */
    public ?int $hasilId = null;

    /**
     * UP-10 — satu kali per request, SEBELUM apa pun membaca status upload.
     *
     * Sengaja di booted(), bukan di dalam sedangBerjalan(): kalau pemulihannya
     * dijalankan belakangan, baris yang sudah terlanjur dibaca (dan di-cache
     * #[Computed]) masih membawa status lama, dan tombolnya menawarkan "Kirim"
     * untuk upload yang barusan ditutup sebagai gagal. Di sini seluruh pembaca
     * melihat keadaan yang sama.
     *
     * Kembaran SyncsWithKimai::kimaiSyncIsRunning() yang memanggil
     * KimaiSynchronizer::failStaleRuns() dengan alasan yang sama.
     */
    public function booted(): void
    {
        $user = Auth::user();

        if ($user !== null) {
            UploadRecovery::failStaleUploads($user);
        }
    }

    /**
     * Draf/upload yang sedang dilihat.
     *
     * Namanya BUKAN `upload()`. Livewire memesan sederet nama pendek di objek
     * `$wire` — daftarnya ada di `aliases` dalam `livewire.esm.js` — dan `upload`
     * salah satunya. Method komponen yang bernama sama tidak akan pernah
     * terjangkau dari sisi browser; lihat catatan panjang di kirim().
     */
    #[Computed]
    public function uploadSaatIni(): ?TimesheetUpload
    {
        if ($this->uploadId === null) {
            return null;
        }

        return TimesheetUpload::query()
            ->where('user_id', Auth::id())
            ->find($this->uploadId);
    }

    /** @return Collection<int, TimesheetUploadEntry> */
    #[Computed]
    public function entries(): Collection
    {
        return $this->uploadSaatIni()?->entries()->orderBy('begin_at')->orderBy('id')->get() ?? collect();
    }

    /** Upload yang baru saja selesai; kembaran uploadSaatIni() untuk panel hasil. */
    #[Computed]
    public function hasilSelesai(): ?TimesheetUpload
    {
        if ($this->hasilId === null) {
            return null;
        }

        return TimesheetUpload::query()
            ->where('user_id', Auth::id())
            ->find($this->hasilId);
    }

    /** @return Collection<int, TimesheetUpload> */
    #[Computed]
    public function riwayat(): Collection
    {
        return TimesheetUpload::query()
            ->where('user_id', Auth::id())
            ->whereNot('status', UploadStatus::Draft->value)
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /** Mengembalikan entri yang bentroknya cuma sebagian ke antrean kirim. */
    public function sertakanBentrok(): void
    {
        $upload = $this->uploadSaatIni();

        if ($upload === null || $upload->status !== UploadStatus::Draft) {
            return;
        }

        $jumlah = app(UploadDrafter::class)->includeOverridable($upload);
        unset($this->uploadSaatIni, $this->entries);

        Notification::make()->success()->title("{$jumlah} entri bentrok ikut dikirim")->send();
    }

    /**
     * Membuang pratinjau yang sedang dilihat.
     *
     * Berlaku juga untuk upload yang berhenti di tengah — dulu hanya `draft`, dan
     * itulah kenapa upload yang nyangkut sama sekali tidak punya jalan keluar dari
     * layar ini. Yang benar-benar masih berjalan tetap ditolak, tetapi ditolak
     * dengan suara, bukan dengan tombol yang hilang tanpa penjelasan.
     */
    public function batalkan(): void
    {
        $upload = $this->uploadSaatIni()?->refresh();

        if ($upload !== null && $upload->isActive() && $this->sedangBerjalan()) {
            Notification::make()->warning()->title('Upload masih berjalan')
                ->body('Tunggu sampai selesai dulu, atau coba lagi beberapa menit lagi.')->send();

            return;
        }

        if ($upload !== null && ! in_array($upload->status, [UploadStatus::Success, UploadStatus::Cancelled], true)) {
            app(UploadDrafter::class)->cancel($upload);
        }

        $this->lupakanDraf();
        $this->setelahBatal();
    }

    /**
     * Langkah 2 — menaruh job ke queue dan langsung kembali.
     *
     * NAMANYA PENTING. Method ini dulu bernama `commit()` dan tombolnya memanggil
     * `wire:click="commit"` — dan tidak pernah sekali pun sampai ke sini. Livewire
     * memesan sederet nama pendek di objek `$wire` (`aliases` di `livewire.esm.js`:
     * on, el, id, js, get, set, call, hook, commit, watch, entangle, dispatch,
     * dispatchTo, dispatchSelf, upload, uploadMultiple, removeUpload, cancelUpload),
     * dan daftar itu diperiksa LEBIH DULU daripada method komponen. `$wire.commit`
     * karena itu menunjuk `$commit` milik Livewire — sinkronisasi state biasa yang
     * mengirim `calls: []`. Servernya membalas 200 dengan render yang identik, jadi
     * dari layar tidak ada bedanya dengan klik yang tidak pernah terkirim: tombolnya
     * diam, tanpa notifikasi, tanpa error. Persis bug yang dilaporkan.
     *
     * Tidak ada peringatan apa pun untuk tabrakan ini — tidak dari Livewire, tidak
     * dari Filament, dan `Livewire::test()->call('commit')` tetap hijau karena
     * memanggil PHP-nya langsung tanpa melewati `$wire`. Penjaganya sekarang
     * LivewireNamingTest, bukan ingatan orang.
     */
    public function kirim(): void
    {
        $user = Auth::user();

        if ($user === null) {
            Notification::make()->danger()->title('Sesi tidak dikenali')
                ->body('Coba muat ulang halamannya lalu masuk lagi.')->send();

            return;
        }

        if ($this->uploadId === null) {
            Notification::make()->warning()->title('Tidak ada draf yang bisa dikirim')
                ->body('Periksa '.$this->objekPeriksa().' dulu, lalu kirim.')->send();

            return;
        }

        // Dibaca ulang dari database, bukan dari baris yang terbaca saat render:
        // draf bisa berubah di antara keduanya — analisa di tab lain membatalkan
        // SELURUH draf milik orang yang sama (lihat UploadDrafter::draft()).
        $upload = $this->uploadSaatIni()?->refresh();

        if ($upload === null) {
            $this->lupakanDraf();

            Notification::make()->danger()->title('Draf ini sudah tidak ada')
                ->body('Pratinjaunya sudah tidak berlaku. Periksa '.$this->objekPeriksa().' sekali lagi.')->send();

            return;
        }

        if ($upload->status === UploadStatus::Cancelled) {
            $this->lupakanDraf();

            Notification::make()->warning()->title('Draf ini sudah dibatalkan')
                ->body('Biasanya karena ada pemeriksaan lain di tab atau halaman lain. Periksa '
                    .$this->objekPeriksa().' sekali lagi.')
                ->send();

            return;
        }

        if ($upload->status === UploadStatus::Success) {
            Notification::make()->success()->title('Upload ini sudah selesai')
                ->body($upload->summary())->send();

            return;
        }

        if ($this->sedangBerjalan()) {
            Notification::make()->warning()->title('Masih ada upload yang berjalan')
                ->body('Tunggu sampai selesai; hasilnya muncul sendiri di halaman ini.')->send();

            return;
        }

        if (! $upload->status->isResumable() && $upload->status !== UploadStatus::Draft) {
            // Sisa yang tidak terduga — jangan pernah diam, sebut statusnya.
            Notification::make()->warning()->title('Upload ini tidak bisa dikirim')
                ->body("Statusnya sekarang \"{$upload->status->getLabel()}\".")->send();

            return;
        }

        if ($upload->pendingEntries()->doesntExist()) {
            // Drafnya ditutup, bukan dibiarkan hidup: pratinjau tanpa satu pun baris
            // yang bisa dikirim tidak punya langkah berikutnya, dan scopeBelumSelesai()
            // akan memulihkannya lagi di setiap kunjungan berikutnya selama statusnya
            // masih `draft` — kartu yang sama menyambut orang terus-menerus sampai ia
            // menebak bahwa Batalkan adalah jalan keluarnya.
            //
            // HANYA `draft`. Upload yang sudah terlanjur mengirim sebagian tidak boleh
            // berubah jadi "Dibatalkan" di Riwayat: itu berbohong tentang entri yang
            // benar-benar masuk ke Kimai.
            if ($upload->status === UploadStatus::Draft) {
                app(UploadDrafter::class)->cancel($upload);
                $this->lupakanDraf();
            }

            Notification::make()->warning()->title('Tidak ada entri yang perlu dikirim')
                ->body('Semua barisnya sudah terkirim atau dilewati.')->send();

            return;
        }

        $project = $this->projectUntukKirim($upload);

        if ($project <= 0) {
            Notification::make()->danger()->title('Project Kimai belum dipilih')
                ->body('Pilih project-nya dulu; tanpa itu entri tidak punya tujuan.')->send();

            return;
        }

        $upload->forceFill([
            'project_id' => $project,
            'status' => UploadStatus::Queued->value,
        ])->save();

        PostTimesheetUpload::markPending($user);
        PostTimesheetUpload::dispatch($user, $upload);

        unset($this->uploadSaatIni, $this->entries, $this->riwayat);

        Notification::make()
            ->success()
            ->title('Upload berjalan di latar belakang')
            ->body('Kamu bisa lanjut kerja; hasilnya muncul di halaman ini.')
            ->send();
    }

    /** Melepas pratinjau yang sudah tidak berlaku supaya tidak jadi tombol hantu. */
    private function lupakanDraf(): void
    {
        $this->uploadId = null;
        unset($this->uploadSaatIni, $this->entries, $this->riwayat);
    }

    /**
     * Target wire:poll selama upload berjalan; sekaligus penutup pratinjau yang tuntas.
     *
     * Di sinilah satu-satunya tempat browser bisa tahu job-nya sudah selesai:
     * pengiriman berjalan di queue, dan queue tidak bisa menyentuh properti komponen.
     * Tanpa pembersihan di sini, upload yang SELESAI meninggalkan kartu pratinjau
     * berisi baris "Terkirim" tanpa satu tombol pun di bawahnya — polling sudah
     * berhenti, dan kartunya baru lenyap kalau halamannya dimuat ulang dengan tangan.
     *
     * Hanya `success`. Yang `partial`/`failed` sengaja DITAHAN di layar: baris
     * merahnya satu-satunya tempat alasan kegagalan per entri terbaca, dan yang masih
     * menyisakan entri `pending` punya tombol "Lanjutkan" yang harus tetap terjangkau.
     *
     * sedangBerjalan() tetap dijaga karena ada celah kecil antara UploadPoster::close()
     * dan lepasnya penanda job: di sana statusnya sudah `success` sementara halaman
     * masih menampilkan tombol "Mengirim…". Melewatkan tick itu aman — $berjalan yang
     * masih true membuat atribut wire:poll bertahan, jadi tick berikutnya yang
     * membersihkan.
     */
    public function refreshUpload(): void
    {
        unset($this->uploadSaatIni, $this->entries, $this->riwayat);

        $upload = $this->uploadSaatIni();

        if ($upload === null || $upload->status !== UploadStatus::Success || $this->sedangBerjalan()) {
            return;
        }

        // Dibaca sebelum lupakanDraf(); setelahnya uploadSaatIni() sudah null.
        $ringkasan = $upload->summary();

        // Tabelnya pergi, kabarnya tinggal. Tanpa ini layar mendadak kosong persis
        // di detik orang paling ingin tahu hasilnya — dan toast bisa terlewat kalau
        // tabnya sedang di belakang.
        $this->hasilId = $upload->id;
        unset($this->hasilSelesai);

        $this->lupakanDraf();
        $this->setelahSukses();

        Notification::make()->success()->title('Upload selesai')->body($ringkasan)->send();
    }

    /** Menutup panel hasil. Namanya BUKAN close() — itu salah satu alias $wire. */
    public function tutupHasil(): void
    {
        $this->hasilId = null;
        unset($this->hasilSelesai);
    }

    public function sedangBerjalan(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return PostTimesheetUpload::isPendingFor($user)
            || PostTimesheetUpload::isRunningFor($user)
            || TimesheetUpload::query()->where('user_id', $user->id)->active()->exists();
    }

    /**
     * Sudah sampai mana pengirimannya.
     *
     * Dihitung dari status per entri, BUKAN dari count_posted: kolom itu baru ditulis
     * UploadPoster::close() setelah seluruh perulangannya selesai, jadi sepanjang job
     * berjalan angkanya tetap 0. Status per baris justru commit satu per satu begitu
     * respons Kimai datang (UploadPoster sengaja tanpa transaksi), dan entries() memang
     * sudah dimuat untuk tabelnya — jadi angka ini tidak menambah satu query pun.
     *
     * `sasaran` adalah seluruh entri yang bukan `skipped`, bukan hanya yang `pending`:
     * saat "Lanjutkan", baris yang sudah masuk di percobaan sebelumnya ikut dihitung
     * selesai, sehingga barnya tidak mundur ke nol.
     *
     * @return array{selesai: int, sasaran: int, persen: int}
     */
    public function kemajuan(): array
    {
        $entries = $this->entries();

        $sasaran = $entries->where('status', '!=', UploadEntryStatus::Skipped)->count();
        $selesai = $entries->whereIn('status', [UploadEntryStatus::Posted, UploadEntryStatus::Failed])->count();

        return [
            'selesai' => $selesai,
            'sasaran' => $sasaran,
            'persen' => $sasaran > 0 ? (int) round($selesai / $sasaran * 100) : 0,
        ];
    }

    /** @return array<string, int> */
    public function ringkasanPerSheet(): array
    {
        $out = [];

        foreach ($this->entries()->groupBy('sheet') as $sheet => $rows) {
            $out[$sheet] = [
                'entri' => $rows->count(),
                'menit' => (int) $rows->sum('duration_minutes'),
                'dilewati' => $rows->where('status', UploadEntryStatus::Skipped)->count(),
            ];
        }

        return $out;
    }

    /**
     * Project tujuan pengiriman. Bawaannya: pilihan di form kalau ada, selain itu
     * yang tersimpan di draf.
     *
     * Dibaca dari state mentah, BUKAN lewat form->getState(): getState()
     * memvalidasi seluruh form, termasuk field yang sudah tidak relevan setelah
     * pratinjau dibuat. Select yang dikosongkan mengirim string kosong, bukan
     * null, jadi `??` saja tidak cukup: (int) '' = 0, dan project 0 berarti
     * seluruh entri ditolak Kimai satu per satu.
     */
    protected function projectUntukKirim(TimesheetUpload $upload): int
    {
        return (int) ($this->data['project_id'] ?? 0) ?: (int) $upload->project_id;
    }

    /** Dipanggil setelah pratinjau dibuang lewat tombol Batalkan. */
    protected function setelahBatal(): void {}

    /** Dipanggil begitu upload tuntas dan pratinjaunya diganti panel hasil. */
    protected function setelahSukses(): void {}

    /** "berkasnya" / "isiannya" — untuk pesan "periksa … sekali lagi". */
    protected function objekPeriksa(): string
    {
        return 'berkasnya';
    }

    /** Draf yang dipulihkan saat halaman dibuka: milik sumber halaman ini saja. */
    protected function drafBelumSelesai(string $source): ?TimesheetUpload
    {
        return TimesheetUpload::query()
            ->where('user_id', Auth::id())
            ->fromSource($source)
            ->belumSelesai()
            ->latest('id')
            ->first();
    }
}
