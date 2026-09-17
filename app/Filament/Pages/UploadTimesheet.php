<?php

namespace App\Filament\Pages;

use App\Domain\Kimai\Exceptions\KimaiException;
use App\Domain\Lembur\PayrollPeriodResolver;
use App\Domain\Timesheet\Exceptions\InvalidWorkbook;
use App\Domain\Timesheet\KimaiCatalog;
use App\Domain\Timesheet\TimesheetWorkbookParser;
use App\Domain\Timesheet\UploadDrafter;
use App\Enums\UploadEntryStatus;
use App\Enums\UploadStatus;
use App\Jobs\PostTimesheetUpload;
use App\Models\TimesheetUpload;
use App\Models\TimesheetUploadEntry;
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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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

    public function mount(): void
    {
        // Draf yang ditinggalkan dipulihkan: pratinjaunya sudah menghabiskan satu
        // perjalanan ke Kimai, dan orang memang wajar menutup tab dulu untuk
        // memastikan project id ke PM-nya.
        $draft = TimesheetUpload::query()
            ->where('user_id', Auth::id())
            ->draft()
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
                    ->helperText('Terpilih dari baris "Project ID" di workbook setelah diperiksa.'),

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

    /** Pesan kenapa daftar project tidak tersedia; null berarti tidak ada masalah. */
    public ?string $katalogError = null;

    /** @var array<int, string>|null memo per request; options() dipanggil berkali-kali per render */
    private ?array $opsiProjectMemo = null;

    /** @return array<int, string> id => "Nama (Customer)" */
    public function opsiProject(): array
    {
        if ($this->opsiProjectMemo !== null) {
            return $this->opsiProjectMemo;
        }

        $user = Auth::user();

        if ($user === null) {
            return [];
        }

        try {
            $projects = app(KimaiCatalog::class)->projects($user);
            $this->katalogError = null;
        } catch (KimaiException $e) {
            $this->katalogError = $e->userMessage();

            return $this->opsiProjectMemo = [];
        }

        $opsi = [];

        foreach ($projects as $project) {
            $opsi[$project['id']] = $project['customer'] !== null
                ? "{$project['name']} ({$project['customer']})"
                : $project['name'];
        }

        return $this->opsiProjectMemo = $opsi;
    }

    public function katalogTersedia(): bool
    {
        return $this->opsiProject() !== [];
    }

    /** Project baru di Kimai tidak perlu menunggu TTL cache. */
    public function muatUlangKatalog(): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        app(KimaiCatalog::class)->forget($user);
        $this->opsiProjectMemo = null;
        unset($this->upload, $this->entries, $this->riwayat);

        Notification::make()->success()->title('Daftar project dimuat ulang')->send();
    }

    /** Dipanggil saat project diganti setelah pratinjau sudah ada. */
    public function resolveUlangActivity(): void
    {
        $upload = $this->upload();
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

        unset($this->upload, $this->entries);

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
     * Workbook kosong dalam format yang berlaku sekarang, dibangun saat diminta.
     *
     * Tidak di-commit sebagai berkas: yang penting justru BENTUK selnya, dan berkas
     * biner di repo tidak bisa direview, tidak bisa di-diff, dan langsung basi
     * begitu formatnya bergeser. Dibangun dari kode berarti template ini selalu
     * cocok dengan parser yang membacanya — ada test yang membuktikannya.
     *
     * Kolom tanggal diisi hari kerja periode payroll berjalan, jadi tinggal
     * mengisi selnya tanpa mengetik tanggal satu per satu.
     */
    public function downloadTemplate(): StreamedResponse
    {
        $book = new Spreadsheet;
        $tanggal = $this->hariKerjaPeriode();

        $pertama = true;

        foreach (TimesheetWorkbookParser::SHEETS as $nama => $bertag) {
            $sheet = $pertama ? $book->getActiveSheet() : $book->createSheet();
            $pertama = false;
            $sheet->setTitle($nama);

            $sheet->setCellValue('A1', 'Customer ID');
            $sheet->setCellValue('A2', 'Project ID');
            $sheet->setCellValue('B2', (int) ($this->data['project_id'] ?? config('kimai.default_project')));

            // Baris tanggal: kolom A sengaja DIBIARKAN KOSONG. Itu penanda yang
            // dipakai parser untuk menemukannya, karena baris meta dan baris slot
            // sama-sama berlabel.
            foreach ($tanggal as $i => $hari) {
                $kolom = Coordinate::stringFromColumnIndex($i + 2);
                $sheet->setCellValue($kolom.'4', ExcelDate::PHPToExcel($hari));
                $sheet->getStyle($kolom.'4')->getNumberFormat()->setFormatCode('ddd dd/mm');
                $sheet->getColumnDimension($kolom)->setWidth(38);
            }

            foreach (self::SLOT_TEMPLATE[$nama] as $i => $label) {
                $sheet->setCellValue('A'.(5 + $i), $label);
            }

            $baris = count(self::SLOT_TEMPLATE[$nama]) + 4;

            $sheet->getColumnDimension('A')->setWidth(18);
            $sheet->getStyle('A1:A'.$baris)->getFont()->setBold(true);
            $sheet->getStyle('B4:'.Coordinate::stringFromColumnIndex(count($tanggal) + 1).'4')
                ->getFont()->setBold(true);
            $sheet->getStyle('B5:'.Coordinate::stringFromColumnIndex(count($tanggal) + 1).$baris)
                ->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

            // Header dan kolom label tetap terlihat saat menggulir ke kanan.
            $sheet->freezePane('B5');
        }

        $this->contohSel($book->getSheetByName('Daily'));

        $book->setActiveSheetIndexByName('Daily');
        $writer = new XlsxWriter($book);

        return response()->streamDownload(function () use ($writer, $book) {
            $writer->save('php://output');
            $book->disconnectWorksheets();
        }, 'Template Timesheet.xlsx');
    }

    /** Satu sel contoh, supaya bentuk penandanya tidak perlu ditebak. */
    private function contohSel(Worksheet $sheet): void
    {
        $contoh = new RichText;
        $contoh->createTextRun('Activity: 12_PROJECT_MEETING')->getFont()->setBold(true);
        $contoh->createText("\n\nDaily Meeting\n1. Ganti nama activity sesuai yang ada di Kimai");

        $sheet->setCellValue('B5', $contoh);
        $sheet->getRowDimension(5)->setRowHeight(70);
    }

    /**
     * Hari kerja periode payroll berjalan. Akhir pekan dilewati; kalau memang ada
     * lembur di Sabtu/Minggu, kolomnya tinggal ditambahkan sendiri.
     *
     * @return array<int, CarbonImmutable>
     */
    private function hariKerjaPeriode(): array
    {
        $bounds = app(PayrollPeriodResolver::class)->boundsFor(today());

        $hari = [];
        $kursor = CarbonImmutable::parse($bounds['start']);
        $akhir = CarbonImmutable::parse($bounds['end']);

        while ($kursor->lte($akhir)) {
            if (! $kursor->isWeekend()) {
                $hari[] = $kursor;
            }

            $kursor = $kursor->addDay();
        }

        return $hari;
    }

    /** Label slot per sheet — sama persis dengan yang dibaca parser. */
    private const SLOT_TEMPLATE = [
        'Daily' => ['9 AM - 10 AM', '10 AM - 12 AM', '1 PM - 3 PM', '3 PM - 5 PM', '5 PM - 6 PM'],
        'Overtime' => [
            '12 AM - 2 AM', '2 AM - 4 AM', '4 AM - 5 AM', '5 AM - 6 AM',
            '9 AM - 10 AM', '10 AM - 12 AM', '1 PM - 3 PM', '3 PM - 5 PM',
            '5 PM - 6 PM', '6 PM - 8 PM', '8 PM - 10 PM', '10 PM - 12 PM',
        ],
    ];

    #[Computed]
    public function upload(): ?TimesheetUpload
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
        return $this->upload()?->entries()->orderBy('begin_at')->orderBy('id')->get() ?? collect();
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
        unset($this->upload, $this->entries, $this->riwayat);

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
        $upload = $this->upload();

        if ($upload === null || $upload->status !== UploadStatus::Draft) {
            return;
        }

        $jumlah = app(UploadDrafter::class)->includeOverridable($upload);
        unset($this->upload, $this->entries);

        Notification::make()->success()->title("{$jumlah} entri bentrok ikut dikirim")->send();
    }

    public function batalkan(): void
    {
        $upload = $this->upload();

        if ($upload !== null && $upload->status === UploadStatus::Draft) {
            app(UploadDrafter::class)->cancel($upload);
        }

        $this->uploadId = null;
        unset($this->upload, $this->entries, $this->riwayat);
        $this->form->fill(['project_id' => (int) config('kimai.default_project')]);
    }

    /** Langkah 2 — menaruh job ke queue dan langsung kembali. */
    public function commit(): void
    {
        $upload = $this->upload();
        $user = Auth::user();

        if ($upload === null || $user === null) {
            return;
        }

        if (! in_array($upload->status, [UploadStatus::Draft, UploadStatus::Failed, UploadStatus::Partial], true)) {
            return;
        }

        if ($this->sedangBerjalan()) {
            Notification::make()->warning()->title('Masih ada upload yang berjalan')->send();

            return;
        }

        if ($upload->pendingEntries()->doesntExist()) {
            Notification::make()->warning()->title('Tidak ada entri yang perlu dikirim')->send();

            return;
        }

        // Dibaca dari state mentah, BUKAN lewat form->getState(): getState()
        // memvalidasi seluruh form, termasuk field berkas yang required — dan
        // field itu memang sudah dikosongkan setelah pratinjau selesai dibuat.
        // Pengiriman tidak butuh berkasnya lagi; drafnya sudah ada di database.
        $project = (int) ($this->data['project_id'] ?? $upload->project_id);

        $upload->forceFill([
            'project_id' => $project,
            'status' => UploadStatus::Queued->value,
        ])->save();

        PostTimesheetUpload::markPending($user);
        PostTimesheetUpload::dispatch($user, $upload);

        unset($this->upload, $this->entries, $this->riwayat);

        Notification::make()
            ->success()
            ->title('Upload berjalan di latar belakang')
            ->body('Kamu bisa lanjut kerja; hasilnya muncul di halaman ini.')
            ->send();
    }

    /** Target wire:poll selama upload berjalan. */
    public function refreshUpload(): void
    {
        unset($this->upload, $this->entries, $this->riwayat);
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
