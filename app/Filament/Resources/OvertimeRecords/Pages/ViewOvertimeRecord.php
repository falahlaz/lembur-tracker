<?php

namespace App\Filament\Resources\OvertimeRecords\Pages;

use App\Filament\Resources\OvertimeRecords\OvertimeRecordResource;
use App\Support\Format;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOvertimeRecord extends ViewRecord
{
    protected static string $resource = OvertimeRecordResource::class;

    public function getTitle(): string
    {
        return 'Lembur '.Format::tanggalPanjang($this->getRecord()->overtime_date);
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
