<?php

namespace App\Filament\Pages\Auth;

use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Password dari admin (user baru atau hasil reset) hanya sementara. Selama
 * penanda `must_change_password` menyala, middleware EnsurePasswordIsChanged
 * menahan user di halaman ini. Password lama tidak ditanyakan: user baru saja
 * login memakainya.
 */
class GantiPasswordAwal extends SimplePage
{
    /** @var array<string, mixed> | null */
    public ?array $data = [];

    public static function getRouteName(): string
    {
        return Filament::getCurrentOrDefaultPanel()->generateRouteName('auth.ganti-password');
    }

    public static function getUrl(): string
    {
        return route(static::getRouteName());
    }

    public function mount(): void
    {
        if (! Filament::auth()->user()->mustChangePassword()) {
            $this->redirect(Filament::getUrl());

            return;
        }

        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Ganti Password';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Buat password baru';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Password yang kamu pakai barusan diberikan admin dan hanya sementara. '
            .'Ganti dulu dengan password milikmu sendiri sebelum lanjut.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('password')
                    ->label('Password baru')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::default())
                    ->rule(static fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (Hash::check((string) $value, Filament::auth()->user()->password)) {
                            $fail('Password baru tidak boleh sama dengan password dari admin.');
                        }
                    })
                    ->autocomplete('new-password')
                    ->same('passwordConfirmation')
                    ->validationAttribute('password baru'),

                TextInput::make('passwordConfirmation')
                    ->label('Ulangi password baru')
                    ->password()
                    ->revealable()
                    ->required()
                    ->autocomplete('new-password')
                    ->dehydrated(false)
                    ->validationAttribute('konfirmasi password'),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('Simpan & lanjut')->submit('save'),
                        ])->fullWidth(),
                    ]),
                Actions::make([
                    Action::make('logout')
                        ->label('Keluar')
                        ->link()
                        ->color('gray')
                        ->action('logout'),
                ])->alignCenter(),
            ]);
    }

    public function save(): void
    {
        $user = Filament::auth()->user();
        $data = $this->form->getState();

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
        ])->save();

        // AuthenticateSession membandingkan hash ini; tanpa pembaruan user
        // langsung ter-logout di request berikutnya.
        if (request()->hasSession()) {
            request()->session()->put([
                'password_hash_'.Filament::getAuthGuard() => $user->getAuthPassword(),
            ]);
        }

        Notification::make()->success()->title('Password berhasil diganti')->send();

        $this->redirect(Filament::getUrl());
    }

    public function logout(): void
    {
        Filament::auth()->logout();

        session()->invalidate();
        session()->regenerateToken();

        $this->redirect(Filament::getLoginUrl());
    }
}
