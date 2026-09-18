<?php

namespace App\Filament\Pages;

use App\Domain\Kimai\Exceptions\KimaiException;
use App\Domain\Kimai\KimaiCatalogMirror;
use App\Domain\Timesheet\CatalogResult;
use App\Domain\Timesheet\Exceptions\InvalidWorkbook;
use App\Domain\Timesheet\KimaiCatalog;
use App\Domain\Timesheet\SlotLabel;
use App\Domain\Timesheet\UploadDrafter;
use App\Domain\Timesheet\UploadRecovery;
use App\Enums\UploadEntryStatus;
use App\Enums\UploadStatus;
use App\Jobs\PostTimesheetUpload;
use App\Models\TimesheetUpload;
use App\Models\TimesheetUploadEntry;
use App\Support\Format;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mengunggah workbook timesheet lalu mengirimnya ke Kimai.
 *
 * Berbeda dari ImportLembur yang admin-only: entri masuk Kimai sebagai pemilik
 * token, jadi mengisikan untuk orang lain memang tidak mungkin — halaman ini
 * milik setiap karyawan yang sudah menghubungkan Kimai-nya.
 *
 * Yang disimpan komponen hanya SATU id. Seluruh pratinjau hidup di database
 * (lihat UploadDrafter): 100+ entri berdeskripsi panjang akan ikut diserialisasi
 * di setiap request kalau ditahan sebagai properti Livewire, dan pengirimannya
 * berjalan di queue — yang tidak bisa membaca properti komponen sama sekali.
 */
class UploadTimesheet extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    protected static string|\UnitEnum|null $navigationGroup = 'Pencatatan';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Upload Timesheet';

    protected static ?string $title = 'Upload Timesheet ke Kimai';

    protected string $view = 'filament.pages.upload-timesheet';

    public ?array $data = [];

    public ?int $uploadId = null;

    /** Hanya relevan bagi yang sudah memasang API key, seperti Riwayat Sync. */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasKimaiConnection() ?? false;
    }

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

    public function mount(): void
    {
        // Draf yang ditinggalkan dipulihkan: pratinjaunya sudah menghabiskan satu
        // perjalanan ke Kimai, dan orang memang wajar menutup tab dulu untuk
        // memastikan project id ke PM-nya.
        //
        // Bukan hanya `draft`. Upload yang sedang berjalan atau berhenti di tengah
        // juga dipulihkan: kalau tidak, refresh saat upload nyangkut membuat
        // SELURUH pratinjau lenyap dan yang tersisa hanya form kosong yang tampak
        // normal — padahal tombol Kirim di bawahnya tetap mati karena upload lama
        // masih aktif. Layar harus menunjukkan keadaan yang sebenarnya.
        $draft = TimesheetUpload::query()
            ->where('user_id', Auth::id())
            ->belumSelesai()
            ->latest('id')
            ->first();

        $this->uploadId = $draft?->id;

        $this->form->fill([
            'project_id' => $draft->project_id ?? (int) config('kimai.default_project'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('berkas')
                    ->label('Workbook timesheet (.xlsx)')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    // Sengaja TIDAK storeFiles(): berkasnya dibaca sekali lalu
                    // selesai. Menyimpannya berarti timesheet satu tim menumpuk di
                    // disk tanpa ada yang membersihkan, dan Filament juga mengganti
                    // namanya jadi UUID sehingga nama aslinya hilang.
                    ->storeFiles(false)
                    ->required()
                    ->helperText('Sheet Daily dan Overtime dibaca dari label jam di kolom A, '
                        .'jadi jumlah barisnya boleh berbeda antar periode.'),

                // Dipilih lewat NAMA. Angka id tidak bisa diverifikasi mata, dan
                // project Kimai berganti tiap tahun — salah satu digit berarti satu
                // periode masuk ke project orang lain.
                Select::make('project_id')
                    ->label('Project Kimai')
                    ->options(fn (): array => $this->opsiProject())
                    ->searchable()
                    ->required()
                    ->live()
                    // Nama activity diresolusi terhadap project; ganti project
                    // berarti nama yang sama bisa menunjuk id yang berbeda.
                    ->afterStateUpdated(fn () => $this->resolveUlangActivity())
                    ->visible(fn (): bool => $this->katalogTersedia())
                    ->helperText(fn (): string => 'Terpilih dari baris "Project ID" di workbook setelah diperiksa.'
                        .($this->katalogDariLokal()
                            ? ' Daftar ini dari data lokal, bukan langsung dari Kimai.'
                            : '')),

                // Jalur mundur: halaman tidak boleh mati hanya karena daftar project
                // gagal dimuat. Pratinjau dan pengiriman tetap berguna dengan id
                // yang diketik manual.
                TextInput::make('project_id')
                    ->label('Project ID Kimai')
                    ->numeric()
                    ->required()
                    ->visible(fn (): bool => ! $this->katalogTersedia())
                    ->helperText('Daftar project tidak bisa diambil dari Kimai, jadi id-nya '
                        .'diisi manual. Pastikan angkanya sebelum mengirim.'),
            ])
            ->statePath('data');
    }

    /**
     * Kenapa Kimai gagal dihubungi; null berarti daftarnya memang datang dari Kimai.
     *
     * Sejak ada cermin lokal, terisinya properti ini TIDAK lagi otomatis berarti
     * daftarnya kosong — bisa saja cermin yang menyelamatkan. Yang membedakan
     * keduanya katalogDariLokal(), dan itulah yang dibaca blade untuk memilih antara
     * peringatan "pakai ID manual" dan keterangan "daftar ini dari data lokal".
     */
    public ?string $katalogError = null;

    /** Memo per request; options() dipanggil berkali-kali per render. */
    private ?CatalogResult $katalogMemo = null;

    private function katalogProject(): CatalogResult
    {
        if ($this->katalogMemo !== null) {
            return $this->katalogMemo;
        }

        $user = Auth::user();

        if ($user === null) {
            return $this->katalogMemo = CatalogResult::kosong('Sesi tidak dikenali.');
        }

        $hasil = app(KimaiCatalog::class)->projectsOrMirror($user);

        $this->katalogError = $hasil->error;

        return $this->katalogMemo = $hasil;
    }

    /** @return array<int, string> id => "Nama (Customer)" */
    public function opsiProject(): array
    {
        $opsi = [];

        foreach ($this->katalogProject()->items as $project) {
            $opsi[$project['id']] = $project['customer'] !== null
                ? "{$project['name']} ({$project['customer']})"
                : $project['name'];
        }

        return $opsi;
    }

    public function katalogTersedia(): bool
    {
        return $this->opsiProject() !== [];
    }

    /** Daftarnya terselamatkan cermin lokal, bukan datang dari Kimai. */
    public function katalogDariLokal(): bool
    {
        return $this->katalogProject()->dariCermin();
    }

    public function terakhirKatalogDisinkronkan(): ?CarbonImmutable
    {
        return app(KimaiCatalogMirror::class)->terakhirDisinkronkan();
    }

    /** "17 Sep 14:20", atau null kalau cerminnya belum pernah diisi. */
    public function terakhirKatalogDisinkronkanTeks(): ?string
    {
        $waktu = $this->terakhirKatalogDisinkronkan();

        return $waktu === null
            ? null
            : Format::tanggalRingkas($waktu).' '
                .$waktu->timezone(config('app.display_timezone'))->format('H:i');
    }

    /**
     * Potongan kalimat ", terakhir disinkronkan 17 Sep 14:20" — atau string kosong.
     *
     * Dirakit di sini, bukan di blade: menyusunnya di sana menuntut variabel blade,
     * dan @php(...) sebaris di berkas ini adalah jebakan (lihat catatan di view).
     */
    public function keteranganSinkronCermin(): string
    {
        $teks = $this->terakhirKatalogDisinkronkanTeks();

        return $teks === null ? '' : ", terakhir disinkronkan {$teks}";
    }

    /** Project baru di Kimai tidak perlu menunggu TTL cache. */
    public function muatUlangKatalog(): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        app(KimaiCatalog::class)->forget($user);
        $this->katalogMemo = null;
        unset($this->uploadSaatIni, $this->entries, $this->riwayat);

        Notification::make()->success()->title('Daftar project dimuat ulang')->send();
    }

    /** Dipanggil saat project diganti setelah pratinjau sudah ada. */
    public function resolveUlangActivity(): void
    {
        $upload = $this->uploadSaatIni();
        $projectId = (int) ($this->data['project_id'] ?? 0);

        if ($upload === null || $upload->status !== UploadStatus::Draft || $projectId <= 0) {
            return;
        }

        try {
            $hasil = app(UploadDrafter::class)->reresolveActivities($upload, $projectId);
        } catch (KimaiException $e) {
            Notification::make()->danger()->title('Activity tidak bisa diresolusi ulang')
                ->body($e->userMessage())->send();

            return;
        }

        unset($this->uploadSaatIni, $this->entries);

        if ($hasil['diresolusi'] === 0 && $hasil['gagal'] === 0) {
            return;
        }

        Notification::make()
            ->status($hasil['gagal'] > 0 ? 'warning' : 'success')
            ->title('Activity diresolusi ulang di project baru')
            ->body($hasil['gagal'] > 0
                ? "{$hasil['gagal']} nama tidak ada di project ini."
                : "{$hasil['diresolusi']} entri cocok.")
            ->send();
    }

    /**
     * Workbook contoh dalam format yang berlaku sekarang, dibangun saat diminta.
     * Tidak di-commit sebagai berkas: yang penting justru bentuk selnya, dan berkas
     * biner di repo tidak bisa direview siapa pun.
     */
    public function downloadTemplate(): StreamedResponse
    {
        $book = new Spreadsheet;
        // Daftarnya milik SlotLabel, supaya template ini dan halaman Legenda
        // tidak pernah menampilkan label yang berbeda.
        $slots = SlotLabel::CONTOH_SLOT;

        $pertama = true;

        foreach ($slots as $nama => $labels) {
            $sheet = $pertama ? $book->getActiveSheet() : $book->createSheet();
            $pertama = false;
            $sheet->setTitle($nama);

            $sheet->setCellValue('A1', 'Customer ID')->setCellValue('B1', 112);
            $sheet->setCellValue('A2', 'Project ID')->setCellValue('B2', (int) config('kimai.default_project'));

            // Baris tanggal: kolom A sengaja dibiarkan kosong — itu penanda yang
            // dipakai parser untuk menemukannya.
            $sheet->setCellValue('B4', (int) ExcelDate::PHPToExcel(now()->startOfWeek()));

            foreach ($labels as $i => $label) {
                $sheet->setCellValue('A'.(5 + $i), $label);
            }

            $contoh = new RichText;
            $contoh->createTextRun('Activity: 12_PROJECT_MEETING')->getFont()->setBold(true);
            $contoh->createText("\n\nDaily Meeting\n1. Ganti nama activity sesuai yang ada di Kimai");
            $sheet->setCellValue('B5', $contoh);
        }

        $writer = new XlsxWriter($book);

        return response()->streamDownload(function () use ($writer, $book) {
            $writer->save('php://output');
            $book->disconnectWorksheets();
        }, 'Template Timesheet.xlsx');
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

    /** Langkah 1 — membaca dan memeriksa. Tidak ada satu pun entri yang dikirim. */
    public function analyse(): void
    {
        $state = $this->form->getState();
        $file = $state['berkas'] ?? null;
        $file = is_array($file) ? reset($file) : $file;

        if (! $file instanceof TemporaryUploadedFile) {
            Notification::make()->danger()->title('Berkasnya belum terunggah')->send();

            return;
        }

        try {
            $upload = app(UploadDrafter::class)->draft(
                Auth::user(),
                $file->getRealPath(),
                $file->getClientOriginalName(),
            );
        } catch (InvalidWorkbook $e) {
            Notification::make()->danger()->title('Berkas tidak bisa dibaca')->body($e->userMessage())->send();

            return;
        }

        $this->uploadId = $upload->id;
        unset($this->uploadSaatIni, $this->entries, $this->riwayat);

        $this->form->fill(['project_id' => $upload->project_id]);

        $siap = $upload->count_parsed - $upload->count_skipped;

        Notification::make()
            ->status($upload->duplicates_checked ? 'success' : 'warning')
            ->title("{$siap} dari {$upload->count_parsed} entri siap dikirim")
            ->body($upload->duplicates_checked
                ? 'Periksa pratinjaunya, pastikan Project ID-nya, lalu kirim.'
                : 'Duplikat TIDAK bisa diperiksa karena Kimai tidak terjangkau.')
            ->send();
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
        $this->form->fill(['project_id' => (int) config('kimai.default_project')]);
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
                ->body('Periksa berkasnya dulu, lalu kirim.')->send();

            return;
        }

        // Dibaca ulang dari database, bukan dari baris yang terbaca saat render:
        // draf bisa berubah di antara keduanya — analisa di tab lain membatalkan
        // SELURUH draf milik orang yang sama (lihat UploadDrafter::draft()).
        $upload = $this->uploadSaatIni()?->refresh();

        if ($upload === null) {
            $this->lupakanDraf();

            Notification::make()->danger()->title('Draf ini sudah tidak ada')
                ->body('Pratinjaunya sudah tidak berlaku. Periksa berkasnya sekali lagi.')->send();

            return;
        }

        if ($upload->status === UploadStatus::Cancelled) {
            $this->lupakanDraf();

            Notification::make()->warning()->title('Draf ini sudah dibatalkan')
                ->body('Biasanya karena berkas lain diperiksa di tab lain. Periksa berkasnya sekali lagi.')
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
            Notification::make()->warning()->title('Tidak ada entri yang perlu dikirim')
                ->body('Semua barisnya sudah terkirim atau dilewati.')->send();

            return;
        }

        // Dibaca dari state mentah, BUKAN lewat form->getState(): getState()
        // memvalidasi seluruh form, termasuk field berkas yang required — dan
        // field itu memang sudah dikosongkan setelah pratinjau selesai dibuat.
        // Pengiriman tidak butuh berkasnya lagi; drafnya sudah ada di database.
        //
        // Select yang dikosongkan mengirim string kosong, bukan null, jadi `??`
        // saja tidak cukup: (int) '' = 0, dan project 0 berarti seluruh entri
        // ditolak Kimai satu per satu.
        $project = (int) ($this->data['project_id'] ?? 0) ?: (int) $upload->project_id;

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

    /** Target wire:poll selama upload berjalan. */
    public function refreshUpload(): void
    {
        unset($this->uploadSaatIni, $this->entries, $this->riwayat);
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
}
