<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Password hasil reset admin juga sementara. Field password hanya ikut
     * tersimpan bila diisi, jadi edit biasa tidak menyalakan penanda ini. Admin
     * yang mengganti password-nya sendiri di sini tidak perlu dipaksa lagi.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (filled($data['password'] ?? null) && ! $this->getRecord()->is(Auth::user())) {
            $data['must_change_password'] = true;
        }

        return $data;
    }
}
