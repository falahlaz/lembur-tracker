<?php

namespace App\Filament\Pages;

use App\Domain\Timesheet\Exceptions\InvalidWorkbook;
use App\Domain\Timesheet\UploadDrafter;
use App\Enums\UploadEntryStatus;
use App\Enums\UploadStatus;
use App\Jobs\PostTimesheetUpload;
use App\Models\TimesheetUpload;
use App\Models\TimesheetUploadEntry;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
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

                TextInput::make('project_id')
                    ->label('Project ID Kimai')
                    ->numeric()
                    ->required()
                    ->helperText('Terisi dari workbook setelah diperiksa. Project Kimai berganti '
                        .'setiap tahun — pastikan angkanya sebelum mengirim.'),
            ])
            ->statePath('data');
    }

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
