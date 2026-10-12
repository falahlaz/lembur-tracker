<?php

namespace App\Filament\Pages;

use App\Domain\Lembur\PayrollPeriodResolver;
use App\Exports\RekapLemburExport;
use App\Exports\SplExport;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Format;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** F-08 — ekspor rekap dan SPL per periode payroll atau rentang tanggal bebas. */
class Rekap extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static string|\UnitEnum|null $navigationGroup = 'Lainnya';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Rekap';

    protected static ?string $title = 'Rekap & Export';

    protected string $view = 'filament.pages.rekap';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'mode' => 'periode',
            'payroll_period_id' => app(PayrollPeriodResolver::class)->current()->id,
            'user_id' => Auth::id(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Karyawan')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->visible(fn () => Auth::user()?->isAdmin() ?? false),

                Radio::make('mode')
                    ->label('Cakupan')
                    ->options([
                        'periode' => 'Per periode payroll',
                        'rentang' => 'Rentang tanggal bebas',
                    ])
                    ->default('periode')
                    ->required()
                    ->live(),

                Select::make('payroll_period_id')
                    ->label('Periode payroll')
                    ->options(fn () => PayrollPeriod::query()
                        ->orderByDesc('period_end')
                        ->get()
                        ->mapWithKeys(fn (PayrollPeriod $p) => [
                            $p->id => Format::periode($p->period_start, $p->period_end, $p->label),
                        ]))
                    ->required(fn (Get $get) => $get('mode') === 'periode')
                    ->visible(fn (Get $get) => $get('mode') === 'periode')
                    ->native(false),

                DatePicker::make('dari')
                    ->label('Dari tanggal')
                    ->native(false)
                    ->required(fn (Get $get) => $get('mode') === 'rentang')
                    ->visible(fn (Get $get) => $get('mode') === 'rentang'),

                DatePicker::make('sampai')
                    ->label('Sampai tanggal')
                    ->native(false)
                    ->afterOrEqual('dari')
                    ->required(fn (Get $get) => $get('mode') === 'rentang')
                    ->visible(fn (Get $get) => $get('mode') === 'rentang'),
            ])
            ->statePath('data');
    }

    public function export(): BinaryFileResponse|Renderable
    {
        [$user, $from, $to, $label] = $this->scope();

        $export = new RekapLemburExport($user, $from, $to, $label);

        return Excel::download($export, $export->fileName());
    }

    /** Surat Perintah Lembur dalam template kantor, untuk diunggah tiap tanggal 19. */
    public function exportSpl(): BinaryFileResponse
    {
        [$user, $from, $to] = $this->scope();

        $export = new SplExport($user, $from, $to);

        return response()
            ->download($export->store(), $export->fileName())
            ->deleteFileAfterSend();
    }

    /** Data SPL yang belum diisi di Profil milik karyawan yang dipilih. */
    public function missingSplFields(): array
    {
        $user = Auth::user()->isAdmin() && ! empty($this->data['user_id'])
            ? User::find($this->data['user_id'])
            : Auth::user();

        return $user?->missingSplFields() ?? [];
    }

    /** @return array{0: User, 1: CarbonInterface, 2: CarbonInterface, 3: string} */
    private function scope(): array
    {
        $data = $this->form->getState();

        $user = Auth::user()->isAdmin() && ! empty($data['user_id'])
            ? User::findOrFail($data['user_id'])
            : Auth::user();

        if (($data['mode'] ?? 'periode') === 'periode') {
            $period = PayrollPeriod::findOrFail($data['payroll_period_id']);

            return [$user, $period->period_start, $period->period_end, $period->label];
        }

        $from = Date::parse($data['dari']);
        $to = Date::parse($data['sampai']);

        return [$user, $from, $to, $from->translatedFormat('j M Y').' - '.$to->translatedFormat('j M Y')];
    }
}
