<?php

namespace App\Filament\Resources\OvertimeRules\Pages;

use App\Filament\Resources\OvertimeRules\OvertimeRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOvertimeRules extends ListRecords
{
    protected static string $resource = OvertimeRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
