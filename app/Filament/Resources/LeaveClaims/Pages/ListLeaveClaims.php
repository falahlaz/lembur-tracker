<?php

namespace App\Filament\Resources\LeaveClaims\Pages;

use App\Filament\Resources\LeaveClaims\LeaveClaimResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLeaveClaims extends ListRecords
{
    protected static string $resource = LeaveClaimResource::class;

    public function getTitle(): string
    {
        return 'Cuti Pengganti';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Ajukan Klaim'),
        ];
    }
}
