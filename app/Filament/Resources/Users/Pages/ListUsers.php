<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Support\Registration;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return Registration::isOpen()
            ? 'Registrasi sedang dibuka. Bagikan link ini: '.Filament::getRegistrationUrl()
            : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Dibuka sebentar saat ada teman yang mau ikut memakai, lalu ditutup lagi.
            Action::make('bukaRegistrasi')
                ->label('Buka registrasi')
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('success')
                ->visible(fn () => ! Registration::isOpen())
                ->requiresConfirmation()
                ->modalHeading('Buka registrasi?')
                ->modalDescription('Siapa pun yang punya link-nya bisa membuat akun karyawan sendiri '
                    .'sampai registrasi ditutup lagi.')
                ->action(function (): void {
                    Registration::open();

                    Notification::make()->success()->title('Registrasi dibuka')
                        ->body('Bagikan link ini: '.Filament::getRegistrationUrl())
                        ->persistent()->send();
                }),

            Action::make('tutupRegistrasi')
                ->label('Tutup registrasi')
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('danger')
                ->visible(fn () => Registration::isOpen())
                ->requiresConfirmation()
                ->modalHeading('Tutup registrasi?')
                ->modalDescription('Akun yang sudah terdaftar tetap ada. Link registrasi berhenti bisa dipakai.')
                ->action(function (): void {
                    Registration::close();

                    Notification::make()->success()->title('Registrasi ditutup')->send();
                }),

            CreateAction::make(),
        ];
    }
}
