<?php

namespace App\Filament\Resources\OvertimeRecords\Schemas;

use App\Domain\Lembur\DurationCalculator;
use App\Domain\Lembur\OvertimeDayCalculator;
use App\Enums\OvertimeStatus;
use App\Enums\Role;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Rules\EvidenceReviewedBeforeSubmission;
use App\Rules\NoOverlappingOvertimeSession;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;

/**
 * F-02 — layar terpenting di seluruh aplikasi (P-6). Satu kolom, tanpa scroll
 * horizontal, bisa diselesaikan dengan ibu jari dari kasur jam 11 malam.
 *
 * Preview hak adalah jantungnya: ia memperbarui diri saat user masih mengisi,
 * tepat di bawah input waktu — di titik di mana user sedang penasaran. Inilah
 * yang mengubah pengisian dari "setor data" jadi "lihat hasil" (P-3).
 */
class OvertimeRecordForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // OQ-2 — admin boleh mencatat atas nama user lain, untuk backfill.
                Select::make('user_id')
                    ->label('Karyawan')
                    ->relationship('user', 'name', fn ($query) => $query->where('is_active', true))
                    ->searchable()
                    ->preload()
                    ->default(fn () => Auth::id())
                    ->required()
                    ->live()
                    ->visible(fn () => Auth::user()?->isAdmin() ?? false)
                    ->helperText('Dicatat atas nama karyawan ini. Tercatat di audit trail.'),

                DatePicker::make('overtime_date')
                    ->label('Tanggal lembur')
                    ->native(false)
                    ->displayFormat('j F Y')
                    ->default(fn () => today())
                    ->required()
                    ->live()
                    // F-02 — tidak boleh masa depan, tidak lebih dari 60 hari ke belakang.
                    ->maxDate(fn () => today())
                    ->minDate(fn () => today()->subDays(60))
                    ->helperText('Maksimal 60 hari ke belakang.'),

                Radio::make('quick_date')
                    ->label('')
                    ->dehydrated(false)
                    ->inline()
                    ->options([
                        'today' => 'Hari ini',
                        'yesterday' => 'Kemarin',
                    ])
                    // Menghilangkan interaksi date picker untuk 90% kasus nyata.
                    ->afterStateUpdated(fn ($state, $set) => $set(
                        'overtime_date',
                        $state === 'yesterday' ? today()->subDay() : today(),
                    ))
                    ->live(),

                TimePicker::make('start_time')
                    ->label('Jam mulai')
                    ->seconds(false)
                    // Native picker memicu roda jam bawaan OS di HP (Design Brief §7).
                    ->native(true)
                    ->required()
                    ->live(debounce: 300),

                TimePicker::make('end_time')
                    ->label('Jam selesai')
                    ->seconds(false)
                    ->native(true)
                    ->required()
                    ->live(debounce: 300)
                    ->rules(fn (Get $get, ?OvertimeRecord $record) => [
                        new NoOverlappingOvertimeSession(
                            userId: self::resolveUser($get)->id,
                            date: $get('overtime_date'),
                            startTime: $get('start_time'),
                            ignoreRecordId: $record?->id,
                        ),
                    ])
                    ->helperText(fn (Get $get) => self::crossesMidnightHint($get)),

                // ── Preview hak ────────────────────────────────────────────────
                Section::make('Preview hak')
                    ->description('Dihitung dari isian di atas. Belum disimpan.')
                    ->schema([
                        \Filament\Schemas\Components\Text::make(fn (Get $get, ?OvertimeRecord $record) => new \Illuminate\Support\HtmlString(
                            view('filament.previews.entitlement', [
                                'preview' => self::buildPreview($get, $record),
                            ])->render()
                        )),
                    ])
                    ->visible(fn (Get $get) => filled($get('start_time')) && filled($get('end_time')))
                    ->columnSpanFull(),

                // SY-10 — wajib terlihat bila > 0. Tanpa ini, record 19:00–23:00
                // berdurasi 3 jam 30 menit terbaca seperti bug.
                TextInput::make('break_minutes')
                    ->label('Istirahat (menit)')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (?OvertimeRecord $record) => (int) ($record?->break_minutes ?? 0) > 0)
                    ->helperText('Sudah dipotong dari durasi, sesuai catatan di Kimai.'),

                Textarea::make('work_description')
                    ->label('Deskripsi pekerjaan')
                    ->rows(3)
                    ->required()
                    ->minLength(10)
                    ->maxLength(2000),

                TextInput::make('evidence_url')
                    ->label('URL evidence')
                    ->url()
                    ->required()
                    ->live(debounce: 300)
                    ->maxLength(2048)
                    ->helperText(fn (?OvertimeRecord $record) => $record?->needsEvidenceReview()
                        // SY-12 — dikatakan terus terang: link ini pengisi sementara.
                        ? 'Sekarang masih berisi deep link Kimai. Ganti dengan link SPL atau '
                            .'timesheet di OneDrive kamu sebelum mengajukan.'
                        : 'Link SPL atau timesheet KIMAI di OneDrive kamu'),

                Select::make('status')
                    ->label('Status')
                    ->options(OvertimeStatus::class)
                    ->default(OvertimeStatus::Recorded)
                    ->required()
                    ->native(false)
                    ->rules(fn (Get $get, ?OvertimeRecord $record) => [
                        new EvidenceReviewedBeforeSubmission(
                            needsReview: (bool) $record?->needsEvidenceReview(),
                            evidenceUrl: $get('evidence_url'),
                            placeholderUrl: $record?->getOriginal('evidence_url'),
                        ),
                    ]),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(2)
                    ->maxLength(2000)
                    ->helperText('Opsional.'),
            ])
            ->columns(1);
    }

    /** Preview memakai kalkulator yang SAMA dengan yang menulis saat simpan. */
    private static function buildPreview(Get $get, ?OvertimeRecord $record)
    {
        $user = self::resolveUser($get);
        $date = $get('overtime_date') ? Date::parse($get('overtime_date')) : today();

        return app(OvertimeDayCalculator::class)->preview(
            user: $user,
            date: $date,
            startTime: (string) $get('start_time'),
            endTime: (string) $get('end_time'),
            excludeRecordId: $record?->id,
            // SY-10 — preview ikut memotong istirahat, supaya angkanya sama
            // persis dengan yang akan tersimpan.
            breakMinutes: (int) ($get('break_minutes') ?? $record?->break_minutes ?? 0),
        );
    }

    private static function resolveUser(Get $get): User
    {
        $id = $get('user_id');

        return ($id ? User::find($id) : null) ?? Auth::user();
    }

    private static function crossesMidnightHint(Get $get): ?string
    {
        $start = (string) $get('start_time');
        $end = (string) $get('end_time');

        if (blank($start) || blank($end) || ! DurationCalculator::crossesMidnight($start, $end)) {
            return null;
        }

        return 'Lewat tengah malam — durasinya tetap dihitung pada tanggal jam mulai.';
    }
}
