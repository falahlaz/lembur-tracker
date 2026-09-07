<?php

namespace App\Filament\Pages;

use App\Domain\Lembur\PayrollPeriodResolver;
use App\Exports\RekapLemburExport;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Format;
use BackedEnum;
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

/** F-08 — ekspor rekap per periode payroll atau rentang tanggal bebas. */
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
        $data = $this->form->getState();

        $user = Auth::user()->isAdmin() && ! empty($data['user_id'])
            ? User::findOrFail($data['user_id'])
            : Auth::user();

        if (($data['mode'] ?? 'periode') === 'periode') {
            $period = PayrollPeriod::findOrFail($data['payroll_period_id']);
            $from = $period->period_start;
            $to = $period->period_end;
            $label = $period->label;
        } else {
            $from = Date::parse($data['dari']);
            $to = Date::parse($data['sampai']);
            $label = $from->translatedFormat('j M Y').' - '.$to->translatedFormat('j M Y');
        }

        $export = new RekapLemburExport($user, $from, $to, $label);

        return Excel::download($export, $export->fileName());
    }
}
