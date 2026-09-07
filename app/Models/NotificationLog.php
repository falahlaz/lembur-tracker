<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** F-07 — kunci idempotensi reminder. */
#[Fillable(['user_id', 'type', 'related_id', 'channel', 'sent_at'])]
class NotificationLog extends Model
{
    use HasFactory;

    public const EXPIRY_H7 = 'balance_expiring_h7';
    public const EXPIRY_H2 = 'balance_expiring_h2';
    public const CUTOFF_APPROACHING = 'cutoff_approaching';
    public const PERIOD_SUMMARY = 'period_summary';
    public const CLAIM_NEEDS_REVIEW = 'claim_needs_review';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function alreadySent(int $userId, string $type, ?int $relatedId = null): bool
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('related_id', $relatedId)
            ->exists();
    }
}
