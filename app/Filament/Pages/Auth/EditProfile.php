<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use SensitiveParameter;

/**
 * Profil milik user sendiri: nama dan password. Email tetap dikelola admin
 * karena dipakai untuk login dan notifikasi. Sengaja tanpa foto profil —
 * penyimpanan server terbatas, avatar cukup inisial bawaan Filament.
 */
class EditProfile extends BaseEditProfile
{
    protected static ?string $title = 'Profil Saya';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent()->label('Nama'),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    protected function getEmailFormComponent(): Component
    {
        // Hanya tampil; tidak ikut tersimpan walau state-nya dimanipulasi.
        return TextInput::make('email')
            ->label('Email')
            ->disabled()
            ->dehydrated(false)
            ->helperText('Hubungi admin bila email perlu diganti.');
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->label('Password baru')
            ->helperText('Kosongkan bila tidak ingin mengganti password. Minimal 8 karakter.');
    }

    /** Email tidak bisa diubah di sini, jadi password lama hanya ditanya saat ganti password. */
    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()
            ->visible(fn (Get $get): bool => filled($get('password')));
    }

    /** Password pilihan user sendiri — penanda password sementara ikut padam. */
    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        if (array_key_exists('password', $data)) {
            $data['must_change_password'] = false;
        }

        return $data;
    }
}
