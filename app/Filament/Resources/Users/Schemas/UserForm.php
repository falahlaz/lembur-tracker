<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/** F-01 — admin membuat, menonaktifkan, dan mereset password user. */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identitas')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Nama')->required()->maxLength(255),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    Select::make('role')
                        ->label('Peran')
                        ->options(Role::class)
                        ->default(Role::Employee)
                        ->required()
                        ->native(false),

                    Select::make('manager_id')
                        ->label('Atasan')
                        ->relationship(
                            name: 'manager',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true),
                        )
                        ->searchable()
                        ->preload()
                        ->placeholder('Belum ditentukan')
                        ->helperText('Dipakai fase 2 untuk akses read-only atasan.'),
                ]),

            Section::make('Akses')
                ->columns(2)
                ->schema([
                    // Tidak ada self-registration; password selalu diberikan admin.
                    TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->required(fn (?User $record) => $record === null)
                        ->dehydrated(fn ($state) => filled($state))
                        ->helperText(fn (?User $record) => $record
                            ? 'Kosongkan bila tidak ingin mengganti password.'
                            : 'Minimal 8 karakter.'),

                    Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true)
                        // F-01 — user nonaktif tidak bisa login, tetapi datanya tetap tersimpan.
                        ->helperText('User nonaktif tidak bisa masuk, tetapi seluruh catatannya tetap tersimpan.'),
                ]),

            Section::make('Preferensi awal')
                ->columns(2)
                ->schema([
                    Toggle::make('rounding_enabled')
                        ->label('Pembulatan durasi')
                        ->helperText('Default mati, sesuai BR-04. User bisa mengubahnya sendiri.'),

                    \Filament\Forms\Components\TimePicker::make('default_late_arrival_time')
                        ->label('Jam masuk default (datang siang)')
                        ->seconds(false)
                        ->default('13:00'),
                ]),
        ]);
    }
}
