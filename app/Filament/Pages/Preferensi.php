<?php

namespace App\Filament\Pages;

use App\Domain\Kimai\KimaiConnection;
use App\Models\NotificationLog;
use App\Support\Format;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
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
            // Token TIDAK pernah diisikan kembali ke form (§9). Yang tampil hanya
            // masknya sebagai placeholder, dan field ini selalu mulai kosong.
            'kimai_token' => null,
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
                Section::make('Integrasi Kimai')
                    ->description('Jam lembur ditarik langsung dari timesheet bertag Overtime, '
                        .'supaya kamu tidak perlu mengetiknya dua kali.')
                    ->schema([
                        Text::make(fn () => $this->kimaiStatusLine()),

                        TextInput::make('kimai_token')
                            ->label('API Key Kimai')
                            ->password()
                            ->revealable(false)
                            ->autocomplete(false)
                            ->placeholder(fn () => Auth::user()->kimaiTokenMask() ?? 'Tempel token dari Kimai')
                            ->helperText('Buat di Kimai lewat Profil → API Access. Token hanya ditampilkan '
                                .'sekali saat dibuat, jadi salin utuh sebelum menutup halamannya.')
                            // F-11 — tersimpan hanya bila tes koneksi lulus.
                            ->hintAction(
                                Action::make('simpanToken')
                                    ->label('Simpan & tes koneksi')
                                    ->action('saveKimaiToken'),
                            ),
                    ])
                    ->footerActions([
                        Action::make('tesKoneksi')
                            ->label('Tes koneksi')
                            ->color('gray')
                            ->visible(fn () => Auth::user()->hasKimaiConnection())
                            ->action('testKimaiConnection'),
                        Action::make('hapusKoneksi')
                            ->label('Hapus koneksi')
                            ->color('danger')
                            ->visible(fn () => Auth::user()->hasKimaiConnection())
                            ->requiresConfirmation()
                            ->modalHeading('Hapus koneksi Kimai?')
                            // §9 Rotasi — yang hilang hanya tokennya.
                            ->modalDescription('Token dihapus dari database seketika. '
                                .'Lembur yang sudah tertarik tetap ada.')
                            ->action('forgetKimaiConnection'),
                    ]),

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

    /** F-11 — status koneksi, ditulis untuk dibaca manusia. */
    public function kimaiStatusLine(): string
    {
        $user = Auth::user();

        if (! $user->hasKimaiConnection()) {
            return 'Tidak terhubung';
        }

        return $user->kimai_token_valid_at === null
            ? 'Token ditolak — buat token baru di Kimai'
            : 'Terhubung · terakhir dipakai '.Format::tanggalRingkas($user->kimai_token_valid_at)
                .' '.$user->kimai_token_valid_at->timezone(config('app.display_timezone'))->format('H:i');
    }

    public function saveKimaiToken(): void
    {
        $token = (string) ($this->form->getState()['kimai_token'] ?? '');
        $result = app(KimaiConnection::class)->store(Auth::user(), $token);

        $this->form->fill(array_merge($this->form->getState(), ['kimai_token' => null]));

        $result->ok
            ? Notification::make()->success()->title('Kimai terhubung')
                ->body('Token tersimpan. Sekarang tombol Sync sudah bisa dipakai.')->send()
            : Notification::make()->danger()->title('Token belum tersimpan')
                ->body($result->message)->send();
    }

    public function testKimaiConnection(): void
    {
        $user = Auth::user();
        $result = app(KimaiConnection::class)->test((string) $user->kimai_api_token);

        if ($result->ok) {
            $user->kimai_token_valid_at = now();
            $user->save();

            Notification::make()->success()->title('Koneksi Kimai berhasil')->send();

            return;
        }

        Notification::make()->danger()->title('Koneksi Kimai bermasalah')->body($result->message)->send();
    }

    public function forgetKimaiConnection(): void
    {
        app(KimaiConnection::class)->forget(Auth::user());

        Notification::make()->success()->title('Koneksi Kimai dihapus')
            ->body('Lembur yang sudah tertarik tetap tersimpan.')->send();
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
