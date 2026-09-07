<?php

namespace App\Filament\Resources\OvertimeRecords\Pages;

use App\Filament\Resources\OvertimeRecords\OvertimeRecordResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOvertimeRecords extends ListRecords
{
    protected static string $resource = OvertimeRecordResource::class;

    public function getTitle(): string
    {
        return 'Lembur';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Catat Lembur'),
        ];
    }
}
