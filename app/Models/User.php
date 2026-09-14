<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name', 'email', 'password', 'role', 'manager_id', 'is_active',
    'rounding_enabled', 'notification_prefs', 'default_late_arrival_time',
])]
#[Hidden(['password', 'remember_token', 'kimai_api_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'rounding_enabled' => 'boolean',
            'notification_prefs' => 'array',
            // §9 — AES-256-CBC lewat APP_KEY. Tidak pernah disimpan plaintext.
            'kimai_api_token' => 'encrypted',
            'kimai_token_valid_at' => 'datetime',
            'kimai_auto_sync' => 'boolean',
            'kimai_synced_through' => 'immutable_date:Y-m-d',
        ];
    }

    /** F-01 — user nonaktif tidak bisa login, tetapi datanya tetap tersimpan. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /** F-07 — setiap jenis reminder dapat dimatikan per user; default menyala. */
    public function wantsNotification(string $type): bool
    {
        return (bool) ($this->notification_prefs[$type] ?? true);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function overtimeRecords(): HasMany
    {
        return $this->hasMany(OvertimeRecord::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveClaims(): HasMany
    {
        return $this->hasMany(LeaveClaim::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    /** F-12 — tombol sync hanya muncul bila API key sudah tersimpan. */
    public function hasKimaiConnection(): bool
    {
        return filled($this->kimai_api_token);
    }

    /** F-11 — yang pernah ditampilkan kembali hanya 4 karakter terakhir. */
    public function kimaiTokenMask(): ?string
    {
        return $this->kimai_token_last4 === null
            ? null
            : str_repeat('•', 12).$this->kimai_token_last4;
    }
}
