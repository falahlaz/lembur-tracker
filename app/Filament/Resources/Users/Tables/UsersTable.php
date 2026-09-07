<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable()->copyable(),
                TextColumn::make('role')->label('Peran')->badge(),
                TextColumn::make('manager.name')->label('Atasan')->placeholder('—')->toggleable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                IconColumn::make('rounding_enabled')
                    ->label('Pembulatan')
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('overtime_records_count')
                    ->label('Lembur')
                    ->counts('overtimeRecords')
                    ->alignEnd()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')->label('Peran')->options(Role::class),
                TernaryFilter::make('is_active')->label('Status aktif'),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('Belum ada user')
            ->emptyStateDescription('Tambahkan anggota tim supaya mereka bisa mencatat lemburnya sendiri.');
    }
}
