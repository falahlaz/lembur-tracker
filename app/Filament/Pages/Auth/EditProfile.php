<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use SensitiveParameter;

/**
 * Profil milik user sendiri: nama, password, dan data Surat Perintah Lembur.
 * Email tetap dikelola admin karena dipakai untuk login dan notifikasi. Sengaja
 * tanpa foto profil — penyimpanan server terbatas, avatar cukup inisial bawaan
 * Filament. Tanda tangan SPL pengecualian: kecil dan memang wajib ada di berkas.
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
                $this->getSplSection(),
            ]);
    }

    /** Kepala & kaki SPL yang tidak bisa diambil dari catatan lembur. */
    protected function getSplSection(): Component
    {
        return Section::make('Data Surat Perintah Lembur (SPL)')
            ->description('Dipakai saat mengunduh SPL di halaman Rekap. Cukup diisi sekali.')
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('division')
                        ->label('Divisi / Department')
                        ->placeholder('IT Developer')
                        ->maxLength(255),
                    TextInput::make('position')
                        ->label('Jabatan (Role)')
                        ->placeholder('Mobile Developer')
                        ->maxLength(255),
                    TextInput::make('default_project')
                        ->label('Project default')
                        ->placeholder('MyTelkomsel - Native')
                        ->helperText('Dipakai untuk lembur yang tidak berasal dari Kimai.')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('approver1_name')
                        ->label('Penyetuju 1 — nama')
                        ->maxLength(255),
                    TextInput::make('approver1_position')
                        ->label('Penyetuju 1 — posisi')
                        ->maxLength(255),
                    TextInput::make('approver2_name')
                        ->label('Penyetuju 2 — nama')
                        ->maxLength(255),
                    TextInput::make('approver2_position')
                        ->label('Penyetuju 2 — posisi')
                        ->maxLength(255),
                ]),
                FileUpload::make('signature_path')
                    ->label('Tanda tangan')
                    ->image()
                    ->acceptedFileTypes(['image/png', 'image/jpeg'])
                    ->maxSize(1024)
                    // Privat: tanda tangan tidak boleh bisa diunduh orang lain lewat URL.
                    ->disk('local')
                    ->directory('signatures')
                    ->visibility('private')
                    ->helperText('PNG berlatar transparan paling rapi. Maksimal 1 MB.'),
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

        // Tanda tangan lama dibuang begitu diganti atau dihapus, supaya tidak menumpuk.
        $old = $this->getUser()->signature_path;

        if (filled($old) && $old !== ($data['signature_path'] ?? null)) {
            Storage::disk('local')->delete($old);
        }

        return $data;
    }
}
