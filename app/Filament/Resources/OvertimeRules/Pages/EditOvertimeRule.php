<?php

namespace App\Filament\Resources\OvertimeRules\Pages;

use App\Filament\Resources\OvertimeRules\OvertimeRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOvertimeRule extends EditRecord
{
    protected static string $resource = OvertimeRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
