<?php

namespace App\Filament\Pages\Auth;

use App\Domain\Kimai\KimaiConnection;
use App\Enums\Role;
use App\Models\User;
use App\Support\Registration;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Registrasi mandiri — hanya bisa dipakai selama admin membukanya (lihat
 * App\Support\Registration). Saat tertutup halaman ini 404, bukan sekadar
 * disembunyikan dari halaman login.
 *
 * Koneksi Kimai ditawarkan sekalian supaya user baru tidak perlu mencari
 * halaman Preferensi, tapi tetap opsional.
 */
class Register extends BaseRegister
{
    /** Token Kimai yang ditolak harus ikut membatalkan akun — lihat handleRegistration(). */
    protected ?bool $hasDatabaseTransactions = true;

    public function mount(): void
    {
        abort_unless(Registration::isOpen(), 404);

        parent::mount();
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Daftar akun';
    }

    public function register(): ?RegistrationResponse
    {
        // Admin bisa menutup registrasi selagi form ini masih terbuka.
        if (! Registration::isOpen()) {
            Notification::make()->danger()->title('Registrasi sudah ditutup')
                ->body('Hubungi admin kalau kamu masih perlu akun.')->send();

            return null;
        }

        return parent::register();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent()->label('Nama'),
                $this->getEmailFormComponent()->label('Email'),
                $this->getPasswordFormComponent()->label('Password'),
                $this->getPasswordConfirmationFormComponent()->label('Ulangi password'),

                Section::make('Hubungkan ke Kimai (opsional)')
                    ->description('Supaya jam lembur bertag Overtime langsung tertarik dari timesheet. '
                        .'Boleh dilewati dan dihubungkan nanti dari halaman Preferensi.')
                    ->collapsible()
                    ->compact()
                    ->schema([
                        View::make('filament.partials.kimai-token-steps'),

                        TextInput::make('kimai_token')
                            ->label('API Key Kimai')
                            ->password()
                            ->revealable(false)
                            ->autocomplete(false)
                            ->placeholder('Tempel token dari Kimai')
                            ->helperText('Kosongkan kalau belum mau menghubungkan Kimai.')
                            ->hintAction(
                                Action::make('tesKoneksiKimai')
                                    ->label('Tes koneksi')
                                    ->action(fn () => $this->testKimaiToken()),
                            ),
                    ]),
            ]);
    }

    public function testKimaiToken(): void
    {
        $result = app(KimaiConnection::class)->test((string) ($this->data['kimai_token'] ?? ''));

        $result->ok
            ? Notification::make()->success()->title('Koneksi Kimai berhasil')
                ->body('Token akan tersimpan begitu kamu selesai mendaftar.')->send()
            : Notification::make()->danger()->title('Koneksi Kimai bermasalah')
                ->body($result->message)->send();
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $token = trim((string) ($data['kimai_token'] ?? ''));
        unset($data['kimai_token']);

        $user = User::query()->create(array_merge($data, [
            'role' => Role::Employee,
            'is_active' => true,
            // Password dipilih sendiri, bukan password sementara dari admin.
            'must_change_password' => false,
            'default_late_arrival_time' => '13:00',
        ]));

        if ($token === '') {
            return $user;
        }

        $result = app(KimaiConnection::class)->store($user, $token);

        // Dijalankan di dalam transaksi register(): melempar di sini ikut
        // membatalkan akun, jadi user tidak mengira Kimai-nya sudah tersambung.
        if (! $result->ok) {
            throw ValidationException::withMessages([
                'data.kimai_token' => $result->message.' Perbaiki tokennya, atau kosongkan kolom ini untuk melewati.',
            ]);
        }

        return $user;
    }
}
