<?php

namespace App\Filament\Resources\LeaveClaims\Schemas;

use App\Domain\Lembur\ClaimValidator;
use App\Domain\Lembur\LeaveAllocator;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Models\LeaveClaim;
use App\Models\User;
use App\Rules\ClaimPassesValidation;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\HtmlString;

/**
 * F-04 — mencatat pengajuan cuti pengganti.
 *
 * Panel alokasi menjawab pertanyaan yang selalu muncul: "saldo yang mana yang
 * kepakai?" Tanpa panel itu FIFO terasa seperti sihir dan user tidak percaya
 * angkanya (Design Brief §4.4).
 */
class LeaveClaimForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Karyawan')
                    ->relationship('user', 'name', fn ($q) => $q->where('is_active', true))
                    ->searchable()
                    ->preload()
                    ->default(fn () => Auth::id())
                    ->required()
                    ->live()
                    ->visible(fn () => Auth::user()?->isAdmin() ?? false),

                Radio::make('claim_type')
                    ->label('Bentuk klaim')
                    ->options(fn () => collect(ClaimType::cases())
                        ->mapWithKeys(fn (ClaimType $t) => [$t->value => $t->getDescription()])
                        ->all())
                    ->default(ClaimType::FullDay->value)
                    ->required()
                    ->live(),

                DatePicker::make('claim_date')
                    ->label('Tanggal cuti')
                    ->native(false)
                    ->displayFormat('j F Y')
                    ->required()
                    ->live(debounce: 400)
                    ->rules(fn (Get $get, ?LeaveClaim $record) => [
                        new ClaimPassesValidation(
                            user: self::resolveUser($get),
                            claimType: $get('claim_type'),
                            ignoreClaimId: $record?->id,
                        ),
                    ]),

                TimePicker::make('arrival_time')
                    ->label('Jam masuk')
                    ->seconds(false)
                    ->native(true)
                    ->default(fn () => Auth::user()?->default_late_arrival_time ?? '13:00')
                    ->required(fn (Get $get) => $get('claim_type') === ClaimType::LateArrival->value)
                    ->visible(fn (Get $get) => $get('claim_type') === ClaimType::LateArrival->value),

                // ── Panel alokasi ──────────────────────────────────────────────
                Section::make('Saldo yang akan dipakai')
                    ->schema([
                        Text::make(fn (Get $get, ?LeaveClaim $record) => new HtmlString(
                            view('filament.previews.allocation', self::allocationData($get, $record))->render()
                        )),
                    ])
                    ->visible(fn (Get $get) => filled($get('claim_date')) && filled($get('claim_type')))
                    ->columnSpanFull(),

                Select::make('status')
                    ->label('Status')
                    ->options(ClaimStatus::class)
                    ->default(ClaimStatus::Draft)
                    ->required()
                    ->native(false)
                    ->helperText('Saldo mulai ditahan sejak status "Diajukan".'),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(2)
                    ->maxLength(2000),
            ])
            ->columns(1);
    }

    /** Dry-run alokasi: menghasilkan angka yang PERSIS sama dengan yang nanti tersimpan. */
    public static function allocationData(Get $get, ?LeaveClaim $record): array
    {
        $user = self::resolveUser($get);
        $type = ClaimType::tryFrom((string) $get('claim_type')) ?? ClaimType::FullDay;
        $date = Date::parse($get('claim_date'));

        $validator = app(ClaimValidator::class);
        $required = $validator->minutesRequired($date, $type);

        return [
            'plan' => app(LeaveAllocator::class)->plan($user, $date, $required, $record?->id),
            'validation' => $validator->validate($user, $date, $type, $record?->id),
            'quotaUsed' => $validator->quotaUsedIn($user, $date, $record?->id),
            'quotaLimit' => $validator->quotaLimitFor($date),
            'monthLabel' => ucfirst($date->translatedFormat('F')),
        ];
    }

    private static function resolveUser(Get $get): User
    {
        $id = $get('user_id');

        return ($id ? User::find($id) : null) ?? Auth::user();
    }
}
