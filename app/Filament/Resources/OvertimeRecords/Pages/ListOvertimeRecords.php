<?php

namespace App\Filament\Resources\OvertimeRecords\Pages;

use App\Filament\Concerns\SyncsWithKimai;
use App\Filament\Resources\OvertimeRecords\OvertimeRecordResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOvertimeRecords extends ListRecords
{
    use SyncsWithKimai;

    protected static string $resource = OvertimeRecordResource::class;

    public function getTitle(): string
    {
        return 'Lembur';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Catat Lembur'),
            // F-12 — tombol sync juga hadir di header Daftar Lembur.
            $this->kimaiSyncAction(),
        ];
    }
}
