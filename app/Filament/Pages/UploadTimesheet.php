<?php

namespace App\Filament\Pages;

use App\Domain\Kimai\Exceptions\KimaiException;
use App\Domain\Timesheet\Exceptions\InvalidWorkbook;
use App\Domain\Timesheet\SlotLabel;
use App\Domain\Timesheet\UploadDrafter;
use App\Enums\UploadStatus;
use App\Filament\Concerns\PicksKimaiProject;
use App\Filament\Concerns\PreviewsTimesheetUpload;
use App\Models\TimesheetUpload;
use App\Support\Format;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
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
    use PicksKimaiProject;
    use PreviewsTimesheetUpload;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    protected static string|\UnitEnum|null $navigationGroup = 'Pencatatan';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Upload Timesheet';

    protected static ?string $title = 'Upload Timesheet ke Kimai';

    protected string $view = 'filament.pages.upload-timesheet';

    public ?array $data = [];

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
        //
        // Bukan hanya `draft`. Upload yang sedang berjalan atau berhenti di tengah
        // juga dipulihkan: kalau tidak, refresh saat upload nyangkut membuat
        // SELURUH pratinjau lenyap dan yang tersisa hanya form kosong yang tampak
        // normal — padahal tombol Kirim di bawahnya tetap mati karena upload lama
        // masih aktif. Layar harus menunjukkan keadaan yang sebenarnya.
        $draft = $this->drafBelumSelesai(TimesheetUpload::SOURCE_WORKBOOK);

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

    /** Pilihan project kembali ke default; berkas berikutnya belum tentu project yang sama. */
    protected function setelahBatal(): void
    {
        $this->form->fill(['project_id' => (int) config('kimai.default_project')]);
    }
}
