<?php

namespace App\Filament\Resources\OvertimeRecords;

use App\Filament\Resources\OvertimeRecords\Pages\CreateOvertimeRecord;
use App\Filament\Resources\OvertimeRecords\Pages\EditOvertimeRecord;
use App\Filament\Resources\OvertimeRecords\Pages\ListOvertimeRecords;
use App\Filament\Resources\OvertimeRecords\Pages\ViewOvertimeRecord;
use App\Filament\Resources\OvertimeRecords\Schemas\OvertimeRecordForm;
use App\Filament\Resources\OvertimeRecords\Schemas\OvertimeRecordInfolist;
use App\Filament\Resources\OvertimeRecords\Tables\OvertimeRecordsTable;
use App\Models\OvertimeRecord;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class OvertimeRecordResource extends Resource
{
    protected static ?string $model = OvertimeRecord::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|\UnitEnum|null $navigationGroup = 'Pencatatan';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'lembur';

    protected static ?string $pluralModelLabel = 'lembur';

    protected static ?string $navigationLabel = 'Lembur';

    protected static ?string $recordTitleAttribute = 'work_description';

    public static function form(Schema $schema): Schema
    {
        return OvertimeRecordForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OvertimeRecordInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OvertimeRecordsTable::configure($table);
    }

    /**
     * F-01 — user hanya melihat datanya sendiri. Dipasang di query dasar resource
     * supaya berlaku untuk SEMUA halaman turunannya sekaligus; Policy tetap ada
     * sebagai lapis kedua untuk akses per-record.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        return $user?->isAdmin()
            ? $query
            : $query->where('user_id', $user?->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOvertimeRecords::route('/'),
            'create' => CreateOvertimeRecord::route('/create'),
            'view' => ViewOvertimeRecord::route('/{record}'),
            'edit' => EditOvertimeRecord::route('/{record}/edit'),
        ];
    }
}
