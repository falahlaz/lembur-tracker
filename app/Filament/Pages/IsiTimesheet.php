<?php

namespace App\Filament\Pages;

use App\Domain\Timesheet\CatalogResult;
use App\Domain\Timesheet\Exceptions\InvalidManualInput;
use App\Domain\Timesheet\KimaiCatalog;
use App\Domain\Timesheet\ManualEntryBuilder;
use App\Domain\Timesheet\UploadDrafter;
use App\Filament\Concerns\PicksKimaiProject;
use App\Filament\Concerns\PreviewsTimesheetUpload;
use App\Models\TimesheetUpload;
use App\Support\Format;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Mengisi timesheet Kimai untuk satu hari atau lebih sekaligus, langsung dari form.
 *
 * Kimai menolak entri yang lebih dari 2 jam, sehingga satu hari kerja berarti
 * empat sampai lima kali mengisi form Kimai — dan berlipat kalau beberapa hari
 * terlewat. Di sini orang menulis bloknya apa adanya ("09:00–18:00, Development"),
 * ManualEntryBuilder memecahnya menjadi potongan ≤ 2 jam, lalu seluruh alur
 * sesudahnya — pemeriksaan duplikat, pratinjau, pengiriman lewat queue — sama
 * persis dengan Upload Timesheet (PreviewsTimesheetUpload).
 *
 * Seperti Upload, entri masuk sebagai pemilik API key, jadi halaman ini milik
 * setiap karyawan yang sudah menghubungkan Kimai-nya.
 */
class IsiTimesheet extends Page implements HasSchemas
{
    use InteractsWithSchemas;
    use PicksKimaiProject;
    use PreviewsTimesheetUpload;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Pencatatan';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Isi Timesheet';

    protected static ?string $title = 'Isi Timesheet ke Kimai';

    protected string $view = 'filament.pages.isi-timesheet';

    public ?array $data = [];

    /** Memo per request, per project; options() dipanggil berkali-kali per render. */
    private array $activityMemo = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasKimaiConnection() ?? false;
    }

    public function mount(): void
    {
        // Seperti Upload: draf yang ditinggalkan (atau upload yang berhenti di
        // tengah) dipulihkan, supaya layar menunjukkan keadaan sebenarnya.
        $draft = $this->drafBelumSelesai(TimesheetUpload::SOURCE_FORM);

        $this->uploadId = $draft?->id;

        $this->form->fill([
            'project_id' => $draft->project_id ?? (int) config('kimai.default_project'),
            'rows' => [(string) Str::uuid() => $this->barisKosong()],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('project_id')
                    ->label('Project Kimai')
                    ->options(fn (): array => $this->opsiProject())
                    ->searchable()
                    ->required()
                    ->live()
                    // Activity berlaku per project; id yang sama belum tentu ada di
                    // project lain, jadi pilihan lama dikosongkan, bukan ditebak.
                    ->afterStateUpdated(fn () => $this->kosongkanActivity())
                    ->visible(fn (): bool => $this->katalogTersedia())
                    ->helperText(fn (): ?string => $this->katalogDariLokal()
                        ? 'Daftar ini dari data lokal, bukan langsung dari Kimai.'
                        : null),

                TextInput::make('project_id')
                    ->label('Project ID Kimai')
                    ->numeric()
                    ->required()
                    ->visible(fn (): bool => ! $this->katalogTersedia())
                    ->helperText('Daftar project tidak bisa diambil dari Kimai, jadi id-nya diisi manual.'),

                Repeater::make('rows')
                    ->label('Pekerjaan')
                    ->table([
                        // Lebar tetap untuk kolom jam: tanpa ini TimePicker menyusut
                        // sampai jamnya terpotong, dan deskripsi yang justru paling
                        // panjang kebagian sisa yang sempit.
                        TableColumn::make('Tanggal')->markAsRequired()->width('9.5rem'),
                        TableColumn::make('Mulai')->markAsRequired()->width('8.5rem'),
                        TableColumn::make('Durasi')->width('5rem'),
                        TableColumn::make('Selesai')->markAsRequired()->width('8.5rem'),
                        TableColumn::make('Activity')->markAsRequired()->width('12rem'),
                        TableColumn::make('Deskripsi')->markAsRequired()->width('16rem'),
                        TableColumn::make('Lembur')->width('4.5rem'),
                    ])
                    ->schema([
                        DatePicker::make('tanggal')
                            ->hiddenLabel()
                            ->required()
                            ->live(onBlur: true),
                        TimePicker::make('mulai')
                            ->hiddenLabel()
                            ->seconds(false)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->isiSelesaiDariDurasi($get, $set)),
                        // Meniru pasangan "Duration / End" di form Kimai: salah satu
                        // cukup, yang lain menyesuaikan.
                        TextInput::make('durasi')
                            ->hiddenLabel()
                            ->placeholder('j:mm')
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->isiSelesaiDariDurasi($get, $set)),
                        TimePicker::make('selesai')
                            ->hiddenLabel()
                            ->seconds(false)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->isiDurasiDariSelesai($get, $set)),
                        Select::make('activity_id')
                            ->hiddenLabel()
                            ->options(fn (Get $get): array => $this->opsiActivity((int) $get('../../project_id')))
                            ->searchable()
                            ->required(),
                        Textarea::make('deskripsi')
                            ->hiddenLabel()
                            ->rows(1)
                            ->autosize()
                            ->required(),
                        Toggle::make('lembur')
                            ->hiddenLabel()
                            ->live(),
                    ])
                    ->defaultItems(1)
                    ->minItems(1)
                    ->reorderable(false)
                    ->cloneable()
                    ->addActionLabel('Tambah baris')
                    // Baris baru melanjutkan baris terakhir: tanggal, activity, dan
                    // jenisnya sama, jam mulainya jam selesai baris sebelumnya.
                    // Mengisi satu hari penuh jadi cukup mengetik jam selesai dan
                    // deskripsinya saja.
                    ->addAction(fn (Action $action) => $action->action(function (Repeater $component): void {
                        $items = $component->getRawState() ?? [];
                        $terakhir = $items === [] ? [] : end($items);
                        $uuid = $component->generateUuid() ?? (string) Str::uuid();

                        $items[$uuid] = $this->barisLanjutan(is_array($terakhir) ? $terakhir : []);

                        $component->rawState($items);
                        $component->getChildSchema($uuid)->fill($items[$uuid]);
                        $component->callAfterStateUpdated();
                    })),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [$this->salinKeRentangAction()];
    }

    /**
     * Menyalin seluruh baris dari satu tanggal ke banyak tanggal sekaligus — jalan
     * pintas untuk minggu yang isinya kurang lebih sama setiap hari.
     */
    public function salinKeRentangAction(): Action
    {
        return Action::make('salinKeRentang')
            ->label('Salin ke rentang tanggal')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->modalDescription('Semua baris di tanggal sumber disalin ke setiap hari terpilih di rentang ini.')
            ->modalSubmitActionLabel('Salin')
            ->schema([
                Select::make('sumber')
                    ->label('Tanggal sumber')
                    ->options(fn (): array => $this->opsiTanggalSumber())
                    ->default(fn (): ?string => array_key_first($this->opsiTanggalSumber()))
                    ->required(),
                DatePicker::make('dari')->label('Dari tanggal')->required(),
                DatePicker::make('sampai')->label('Sampai tanggal')->required()->afterOrEqual('dari'),
                CheckboxList::make('hari')
                    ->label('Hari')
                    ->options([1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'])
                    ->default([1, 2, 3, 4, 5])
                    ->columns(7)
                    ->required(),
                Toggle::make('lewati_terisi')
                    ->label('Lewati tanggal yang sudah punya baris')
                    ->default(true),
            ])
            ->action(function (array $data): void {
                $hasil = $this->salinBaris(
                    (string) $data['sumber'],
                    CarbonImmutable::parse($data['dari']),
                    CarbonImmutable::parse($data['sampai']),
                    array_map('intval', (array) $data['hari']),
                    (bool) ($data['lewati_terisi'] ?? true),
                );

                Notification::make()
                    ->status($hasil === 0 ? 'warning' : 'success')
                    ->title($hasil === 0 ? 'Tidak ada tanggal yang disalin' : "Disalin ke {$hasil} tanggal")
                    ->send();
            });
    }

    /**
     * @param  array<int, int>  $hari  ISO: 1 = Senin … 7 = Minggu
     * @return int jumlah tanggal yang menerima salinan
     */
    private function salinBaris(string $sumber, CarbonImmutable $dari, CarbonImmutable $sampai, array $hari, bool $lewatiTerisi): int
    {
        $rows = $this->data['rows'] ?? [];

        $template = array_values(array_filter($rows, fn ($row) => ($row['tanggal'] ?? null) === $sumber));

        if ($template === [] || $sampai->lt($dari)) {
            return 0;
        }

        $terisi = array_flip(array_filter(array_map(fn ($row) => $row['tanggal'] ?? null, $rows)));

        // Batas atas supaya rentang yang salah ketik (tahun keliru) tidak menelurkan
        // ribuan baris di browser; builder toh menolaknya di atas upload_max_entries.
        $sampai = $sampai->min($dari->addDays(62));

        $jumlah = 0;

        for ($tanggal = $dari; $tanggal->lte($sampai); $tanggal = $tanggal->addDay()) {
            $kunci = $tanggal->toDateString();

            if ($kunci === $sumber || ! in_array($tanggal->isoWeekday(), $hari, true)) {
                continue;
            }

            if ($lewatiTerisi && isset($terisi[$kunci])) {
                continue;
            }

            foreach ($template as $row) {
                $rows[(string) Str::uuid()] = array_merge($row, ['tanggal' => $kunci]);
            }

            $jumlah++;
        }

        // Diurutkan supaya tabelnya terbaca per hari, bukan menurut urutan klik.
        uasort($rows, fn ($a, $b) => [$a['tanggal'] ?? '', $a['mulai'] ?? ''] <=> [$b['tanggal'] ?? '', $b['mulai'] ?? '']);

        $this->form->fill([...$this->data, 'rows' => $rows]);

        return $jumlah;
    }

    /** Langkah 1 — memecah, memeriksa duplikat, menyimpan draf. Belum ada yang dikirim. */
    public function periksa(): void
    {
        $state = $this->form->getState();
        $projectId = (int) ($state['project_id'] ?? 0);

        try {
            $upload = app(UploadDrafter::class)->draftManual(Auth::user(), $state['rows'] ?? [], $projectId);
        } catch (InvalidManualInput $e) {
            Notification::make()->danger()->title('Isiannya belum bisa diperiksa')
                ->body($e->userMessage())->persistent()->send();

            return;
        }

        $this->uploadId = $upload->id;
        $this->hasilId = null;
        unset($this->uploadSaatIni, $this->entries, $this->riwayat, $this->hasilSelesai);

        $siap = $upload->count_parsed - $upload->count_skipped;

        Notification::make()
            ->status($upload->duplicates_checked ? 'success' : 'warning')
            ->title("{$siap} dari {$upload->count_parsed} entri siap dikirim")
            ->body($upload->duplicates_checked
                ? 'Periksa pratinjaunya di bawah, lalu kirim.'
                : 'Duplikat TIDAK bisa diperiksa karena Kimai tidak terjangkau.')
            ->send();
    }

    /**
     * Ringkasan per tanggal dari isian yang sedang diketik, sebelum Periksa:
     * total jam dan berapa entri Kimai yang akan lahir. Murni hitungan lokal.
     *
     * @return array<string, array{label: string, menit: int, entri: int, menit_daily: int}>
     */
    public function ringkasanIsian(): array
    {
        $out = [];

        foreach ($this->data['rows'] ?? [] as $row) {
            $mulai = ManualEntryBuilder::minuteOfDay($row['mulai'] ?? null);
            $selesai = ManualEntryBuilder::minuteOfDay($row['selesai'] ?? null, asEnd: true);

            if (blank($row['tanggal'] ?? null) || $mulai === null || $selesai === null || $selesai <= $mulai) {
                continue;
            }

            try {
                $tanggal = CarbonImmutable::parse((string) $row['tanggal']);
            } catch (\Throwable) {
                continue;
            }

            $kunci = $tanggal->toDateString();
            $menit = $selesai - $mulai;

            $out[$kunci] ??= ['label' => $tanggal->translatedFormat('D, j M'), 'menit' => 0, 'entri' => 0, 'menit_daily' => 0];
            $out[$kunci]['menit'] += $menit;
            $out[$kunci]['entri'] += ManualEntryBuilder::pieces($menit);

            if (! ($row['lembur'] ?? false)) {
                $out[$kunci]['menit_daily'] += $menit;
            }
        }

        ksort($out);

        return $out;
    }

    public function durasiTeks(int $menit): string
    {
        return Format::durasiRingkas($menit);
    }

    /** @return array<int, string> id => nama */
    public function opsiActivity(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }

        return $this->activityMemo[$projectId] ??= $this->muatActivity($projectId);
    }

    /** @return array<int, string> */
    private function muatActivity(int $projectId): array
    {
        $user = Auth::user();

        $hasil = $user === null
            ? CatalogResult::kosong('Sesi tidak dikenali.')
            : app(KimaiCatalog::class)->activitiesOrMirror($user, $projectId);

        $opsi = [];

        foreach ($hasil->items as $activity) {
            $opsi[(int) $activity['id']] = (string) $activity['name'];
        }

        asort($opsi, SORT_NATURAL | SORT_FLAG_CASE);

        return $opsi;
    }

    /** @return array<string, string> Y-m-d => "Sen, 22 Sep" */
    private function opsiTanggalSumber(): array
    {
        $opsi = [];

        foreach ($this->data['rows'] ?? [] as $row) {
            if (blank($row['tanggal'] ?? null)) {
                continue;
            }

            $opsi[(string) $row['tanggal']] = CarbonImmutable::parse((string) $row['tanggal'])->translatedFormat('D, j M');
        }

        ksort($opsi);

        return $opsi;
    }

    private function kosongkanActivity(): void
    {
        foreach (array_keys($this->data['rows'] ?? []) as $key) {
            $this->data['rows'][$key]['activity_id'] = null;
        }
    }

    private function isiSelesaiDariDurasi(Get $get, Set $set): void
    {
        $mulai = ManualEntryBuilder::minuteOfDay($get('mulai'));
        $durasi = self::bacaDurasi($get('durasi'));

        if ($mulai === null || $durasi === null || $durasi <= 0) {
            return;
        }

        // Tidak melewati tengah malam: 23:00 + 3 jam dipotong jadi 24:00.
        $selesai = min(1440, $mulai + $durasi);

        $set('selesai', sprintf('%02d:%02d', intdiv($selesai, 60) % 24, $selesai % 60));
        $set('durasi', self::tulisDurasi($selesai - $mulai));
    }

    private function isiDurasiDariSelesai(Get $get, Set $set): void
    {
        $mulai = ManualEntryBuilder::minuteOfDay($get('mulai'));
        $selesai = ManualEntryBuilder::minuteOfDay($get('selesai'), asEnd: true);

        $set('durasi', $mulai !== null && $selesai !== null && $selesai > $mulai
            ? self::tulisDurasi($selesai - $mulai)
            : null);
    }

    /** "2:30" → 150, "8" → 480, "1.5" → 90. */
    private static function bacaDurasi(mixed $value): ?int
    {
        $text = trim(str_replace(',', '.', (string) $value));

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $text, $m) === 1) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        if (is_numeric($text)) {
            return (int) round((float) $text * 60);
        }

        return null;
    }

    private static function tulisDurasi(int $menit): string
    {
        return sprintf('%d:%02d', intdiv($menit, 60), $menit % 60);
    }

    /** @return array<string, mixed> */
    private function barisKosong(): array
    {
        return [
            'tanggal' => CarbonImmutable::now(config('kimai.timezone'))->toDateString(),
            'mulai' => '09:00',
            'durasi' => null,
            'selesai' => null,
            'activity_id' => null,
            'deskripsi' => null,
            'lembur' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $terakhir
     * @return array<string, mixed>
     */
    private function barisLanjutan(array $terakhir): array
    {
        if ($terakhir === []) {
            return $this->barisKosong();
        }

        $selesai = ManualEntryBuilder::minuteOfDay($terakhir['selesai'] ?? null, asEnd: true);

        return [
            ...$this->barisKosong(),
            'tanggal' => $terakhir['tanggal'] ?? $this->barisKosong()['tanggal'],
            'mulai' => $selesai !== null && $selesai < 1440
                ? sprintf('%02d:%02d', intdiv($selesai, 60), $selesai % 60)
                : '09:00',
            'activity_id' => $terakhir['activity_id'] ?? null,
            'lembur' => (bool) ($terakhir['lembur'] ?? false),
        ];
    }

    /**
     * Project dikunci pada draf: activity setiap baris sudah diperiksa terhadap
     * project itu. Mengganti project di form sesudahnya harus lewat Periksa ulang.
     */
    protected function projectUntukKirim(TimesheetUpload $upload): int
    {
        return (int) $upload->project_id;
    }

    protected function objekPeriksa(): string
    {
        return 'isiannya';
    }

    /** Isian yang sudah terkirim dikosongkan; project-nya dibiarkan untuk isian berikutnya. */
    protected function setelahSukses(): void
    {
        $this->form->fill([
            'project_id' => $this->data['project_id'] ?? (int) config('kimai.default_project'),
            'rows' => [(string) Str::uuid() => $this->barisKosong()],
        ]);
    }
}
