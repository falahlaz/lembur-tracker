<?php

namespace App\Models;

use App\Enums\BalanceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\CarbonInterface;

/** BR-12 — batch saldo. Satuan SELALU menit. */
#[Fillable([
    'user_id', 'overtime_record_id', 'earned_minutes',
    'consumed_minutes', 'held_minutes', 'earned_date', 'expires_at', 'status',
])]
class LeaveBalance extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'earned_date' => 'immutable_date:Y-m-d',
            'expires_at' => 'immutable_date:Y-m-d',
            'status' => BalanceStatus::class,
            'earned_minutes' => 'integer',
            'consumed_minutes' => 'integer',
            'held_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function overtimeRecord(): BelongsTo
    {
        return $this->belongsTo(OvertimeRecord::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LeaveClaimAllocation::class);
    }

    /** Menit yang masih bisa dialokasikan ke klaim baru. */
    public function remainingMinutes(): int
    {
        return max(0, $this->earned_minutes - $this->consumed_minutes - $this->held_minutes);
    }

    /** BR-17 — batch hangus tidak dihapus, hanya berubah status. */
    public function hasExpiredOn(CarbonInterface $date): bool
    {
        return $this->expires_at->lt($date);
    }

    /** BR-16 — validasi memakai tanggal CUTI DIAMBIL, bukan tanggal pengajuan. */
    public function isUsableOn(CarbonInterface $claimDate): bool
    {
        return $this->status->isAllocatable() && $this->expires_at->gte($claimDate);
    }

    public function daysUntilExpiry(?CarbonInterface $from = null): int
    {
        return (int) ($from ?? today())->startOfDay()->diffInDays($this->expires_at, false);
    }

    /** Status turunan dari angka menit — dipanggil setelah setiap perubahan alokasi. */
    public function syncStatus(): void
    {
        if ($this->status === BalanceStatus::Void) {
            return;
        }

        // Urutannya penting. Saldo yang masih DITAHAN klaim aktif belum terbuang,
        // jadi ia tidak boleh jatuh ke `expired` hanya karena tanggalnya lewat —
        // klaim itu masih menunggu di-settle oleh job harian (§9.3). BR-17 memakai
        // status `expired` untuk menghitung hak yang HILANG percuma; menghitung
        // saldo yang sudah dipesan sebagai hilang akan melebih-lebihkan angkanya.
        $this->status = match (true) {
            $this->consumed_minutes >= $this->earned_minutes => BalanceStatus::Used,
            $this->held_minutes > 0 => BalanceStatus::PartiallyUsed,
            $this->expires_at->lt(today()) => BalanceStatus::Expired,
            $this->consumed_minutes > 0 => BalanceStatus::PartiallyUsed,
            default => BalanceStatus::Active,
        };
    }

    /** BR-14 — urutan FIFO: paling dulu hangus, lalu tanggal lembur paling awal. */
    public function scopeFifo(Builder $query): Builder
    {
        return $query->orderBy('expires_at')->orderBy('earned_date')->orderBy('id');
    }

    public function scopeAllocatable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BalanceStatus::Active->value,
            BalanceStatus::PartiallyUsed->value,
        ]);
    }
}
