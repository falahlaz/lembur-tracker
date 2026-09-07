<?php

namespace App\Filament\Pages;

use App\Models\NotificationLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/** F-09 — preferensi milik user sendiri. */
class Preferensi extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Lainnya';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Preferensi';

    protected static ?string $title = 'Preferensi Saya';

    protected string $view = 'filament.pages.preferensi';

    public ?array $data = [];

    public function mount(): void
    {
        $user = Auth::user();
        $prefs = $user->notification_prefs ?? [];

        $this->form->fill([
            'rounding_enabled' => $user->rounding_enabled,
            'default_late_arrival_time' => $user->default_late_arrival_time,
            'notif_expiry_h7' => $prefs[NotificationLog::EXPIRY_H7] ?? true,
            'notif_expiry_h2' => $prefs[NotificationLog::EXPIRY_H2] ?? true,
            'notif_cutoff' => $prefs[NotificationLog::CUTOFF_APPROACHING] ?? true,
            'notif_summary' => $prefs[NotificationLog::PERIOD_SUMMARY] ?? true,
            'notif_review' => $prefs[NotificationLog::CLAIM_NEEDS_REVIEW] ?? true,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Perhitungan durasi')
                    ->description('Berlaku untuk lembur yang dicatat maupun dihitung ulang setelah ini.')
                    ->schema([
                        Toggle::make('rounding_enabled')
                            ->label('Bulatkan durasi ke jam terdekat')
                            // BR-04 + R-2 — konsekuensinya dijelaskan apa adanya,
                            // termasuk bahwa ia lebih longgar dari bunyi SOP.
                            ->helperText('Kalau nyala, 3 jam 40 menit dihitung 4 jam dan memenuhi tier. '
                                .'Durasi mentah tetap disimpan dan ikut diekspor, jadi perbedaannya selalu terlihat. '
                                .'Perlu diingat: pembulatan ini lebih longgar daripada bunyi SOP "minimal 4 jam".'),
                    ]),

                Section::make('Klaim cuti pengganti')
                    ->schema([
                        TimePicker::make('default_late_arrival_time')
                            ->label('Jam masuk default untuk "datang lebih siang"')
                            ->seconds(false)
                            ->native(true)
                            ->required(),
                    ]),

                Section::make('Notifikasi email')
                    ->description('Semua reminder bisa dimatikan sendiri-sendiri.')
                    ->schema([
                        Checkbox::make('notif_expiry_h7')->label('Saldo akan hangus — 7 hari sebelumnya'),
                        Checkbox::make('notif_expiry_h2')->label('Saldo akan hangus — 2 hari sebelumnya'),
                        Checkbox::make('notif_cutoff')->label('Cut-off mendekat (tanggal 17)'),
                        Checkbox::make('notif_summary')->label('Ringkasan periode (tanggal 19)'),
                        Checkbox::make('notif_review')->label('Klaim perlu ditinjau'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $user = Auth::user();

        $user->update([
            'rounding_enabled' => (bool) $data['rounding_enabled'],
            'default_late_arrival_time' => $data['default_late_arrival_time'],
            'notification_prefs' => [
                NotificationLog::EXPIRY_H7 => (bool) $data['notif_expiry_h7'],
                NotificationLog::EXPIRY_H2 => (bool) $data['notif_expiry_h2'],
                NotificationLog::CUTOFF_APPROACHING => (bool) $data['notif_cutoff'],
                NotificationLog::PERIOD_SUMMARY => (bool) $data['notif_summary'],
                NotificationLog::CLAIM_NEEDS_REVIEW => (bool) $data['notif_review'],
            ],
        ]);

        Notification::make()
            ->success()
            ->title('Preferensi tersimpan')
            ->body($data['rounding_enabled']
                ? 'Pembulatan durasi menyala. Lembur yang sudah tercatat baru ikut berubah saat kamu mengeditnya.'
                : 'Pembulatan durasi mati. Durasi dipakai apa adanya.')
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Simpan preferensi')->submit('save'),
        ];
    }
}
