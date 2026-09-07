<?php

namespace App\Filament\Resources\OvertimeRules;

use App\Filament\Resources\OvertimeRules\Pages\CreateOvertimeRule;
use App\Filament\Resources\OvertimeRules\Pages\EditOvertimeRule;
use App\Filament\Resources\OvertimeRules\Pages\ListOvertimeRules;
use App\Filament\Resources\OvertimeRules\Schemas\OvertimeRuleForm;
use App\Filament\Resources\OvertimeRules\Tables\OvertimeRulesTable;
use App\Models\OvertimeRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/** BR-24 — hanya admin. */
class OvertimeRuleResource extends Resource
{
    protected static ?string $model = OvertimeRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|\UnitEnum|null $navigationGroup = 'Lainnya';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Versi Aturan';

    protected static ?string $modelLabel = 'versi aturan';

    protected static ?string $pluralModelLabel = 'versi aturan';

    public static function canAccess(): bool
    {
        return Auth::user()?->isAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return OvertimeRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OvertimeRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOvertimeRules::route('/'),
            'create' => CreateOvertimeRule::route('/create'),
            'edit' => EditOvertimeRule::route('/{record}/edit'),
        ];
    }
}
