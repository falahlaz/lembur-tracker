<?php

namespace App\Filament\Resources\LeaveClaims;

use App\Filament\Resources\LeaveClaims\Pages\CreateLeaveClaim;
use App\Filament\Resources\LeaveClaims\Pages\EditLeaveClaim;
use App\Filament\Resources\LeaveClaims\Pages\ListLeaveClaims;
use App\Filament\Resources\LeaveClaims\Schemas\LeaveClaimForm;
use App\Filament\Resources\LeaveClaims\Tables\LeaveClaimsTable;
use App\Models\LeaveClaim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class LeaveClaimResource extends Resource
{
    protected static ?string $model = LeaveClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'Pencatatan';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'klaim cuti pengganti';

    protected static ?string $pluralModelLabel = 'klaim cuti pengganti';

    protected static ?string $navigationLabel = 'Cuti Pengganti';

    /** Navigasinya diwakili halaman CutiPengganti; resource ini menyediakan rute form. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return LeaveClaimForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveClaimsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        return $user?->isAdmin() ? $query : $query->where('user_id', $user?->id);
    }

    /** BR-23 — klaim yang perlu ditinjau diangkat ke navigasi, bukan disembunyikan. */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('needs_review', true)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveClaims::route('/'),
            'create' => CreateLeaveClaim::route('/create'),
            'edit' => EditLeaveClaim::route('/{record}/edit'),
        ];
    }
}
