<?php

namespace App\Filament\Pages;

use App\Domain\Lembur\HistoricalImporter;
use App\Domain\Lembur\ImportedRow;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** OQ-4 — impor lembur historis, dengan pratinjau wajib sebelum menyimpan. */
class ImportLembur extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|\UnitEnum|null $navigationGroup = 'Lainnya';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Import Historis';

    protected static ?string $title = 'Import Lembur Historis';

    protected string $view = 'filament.pages.import-lembur';

    public ?array $data = [];

    /** @var Collection<int, ImportedRow>|null */
    public ?Collection $preview = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['user_id' => Auth::id()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Impor untuk karyawan')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),

                FileUpload::make('berkas')
                    ->label('Berkas CSV atau Excel')
                    ->acceptedFileTypes([
                        'text/csv', 'text/plain',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->storeFiles(true)
                    ->disk('local')
                    ->directory('imports')
                    ->required()
                    ->helperText('Kolom: '.implode(', ', HistoricalImporter::HEADERS)
                        .'. Kolom status dan catatan opsional; status kosong dianggap "disetujui".'),
            ])
            ->statePath('data');
    }

    /** Langkah 1 — membaca berkas dan menghitung, tanpa menulis apa pun. */
    public function analyse(): void
    {
        $data = $this->form->getState();
        $path = Storage::disk('local')->path(is_array($data['berkas']) ? reset($data['berkas']) : $data['berkas']);

        $sheets = Excel::toArray(new \App\Imports\RawSheetImport, $path);
        $rows = $sheets[0] ?? [];

        $this->preview = app(HistoricalImporter::class)->parse(
            User::findOrFail($data['user_id']),
            $rows,
        );

        $valid = $this->preview->filter(fn (ImportedRow $r) => $r->isValid())->count();

        Notification::make()
            ->title($valid.' dari '.$this->preview->count().' baris siap diimpor')
            ->body($valid === $this->preview->count()
                ? 'Semua baris lolos pemeriksaan. Periksa pratinjaunya, lalu simpan.'
                : 'Baris yang bermasalah akan dilewati. Perbaiki berkasnya kalau baris itu penting.')
            ->{$valid === $this->preview->count() ? 'success' : 'warning'}()
            ->send();
    }

    /**
     * Langkah 2 — menyimpan hanya baris yang lolos.
     *
     * NAMANYA PENTING, dan bukan `commit()`: Livewire memesan nama itu di objek
     * `$wire` (`aliases` di `livewire.esm.js`), sehingga `wire:click="commit"`
     * memanggil sinkronisasi state bawaan Livewire dan TIDAK PERNAH sampai ke
     * method ini. Penjelasan lengkapnya ada di UploadTimesheet::kirim();
     * penjaganya LivewireNamingTest.
     */
    public function simpan(): void
    {
        if ($this->preview === null) {
            Notification::make()->warning()->title('Belum ada pratinjau')
                ->body('Periksa berkasnya dulu, lalu simpan.')->send();

            return;
        }

        $user = User::findOrFail($this->form->getState()['user_id']);
        $result = app(HistoricalImporter::class)->commit($user, $this->preview);

        $this->preview = null;
        $this->form->fill(['user_id' => $user->id]);

        Notification::make()
            ->success()
            ->title($result['imported'].' lembur historis tersimpan')
            ->body($result['skipped'] > 0
                ? $result['skipped'].' baris dilewati karena bermasalah.'
                : 'Saldo yang masa berlakunya sudah lewat langsung ditandai hangus.')
            ->send();
    }

    /** Contoh berkas, supaya orang tidak menebak-nebak nama kolom. */
    public function downloadTemplate(): BinaryFileResponse
    {
        $path = storage_path('app/template-import-lembur.csv');
        $handle = fopen($path, 'w');

        fputcsv($handle, HistoricalImporter::HEADERS);
        fputcsv($handle, ['2026-01-15', '19:00', '23:30', 'Hotfix payment gateway timeout', 'https://onedrive.example.com/spl/1', 'disetujui', '']);
        fputcsv($handle, ['2026-01-20', '21:00', '02:00', 'Deploy rilis bulanan', 'https://onedrive.example.com/spl/2', '', 'lewat tengah malam']);
        fclose($handle);

        return response()->download($path, 'template-import-lembur.csv')->deleteFileAfterSend();
    }
}
