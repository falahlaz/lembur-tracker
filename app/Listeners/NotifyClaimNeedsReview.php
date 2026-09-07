<?php

namespace App\Listeners;

use App\Domain\Lembur\ReminderDispatcher;
use App\Events\LeaveClaimNeedsReview;
use App\Mail\KlaimPerluDitinjauMail;
use App\Models\NotificationLog;

/** BR-23 — "user menerima notifikasi berisi klaim mana saja yang perlu diperbaiki". */
class NotifyClaimNeedsReview
{
    public function __construct(private readonly ReminderDispatcher $dispatcher) {}

    public function handle(LeaveClaimNeedsReview $event): void
    {
        $user = $event->claim->user;

        if ($user === null) {
            return;
        }

        $this->dispatcher->deliver(
            user: $user,
            type: NotificationLog::CLAIM_NEEDS_REVIEW,
            relatedId: $event->claim->id,
            mailable: new KlaimPerluDitinjauMail($event->claim, $event->balance),
        );
    }
}
