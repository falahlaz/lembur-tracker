<?php

namespace App\Domain\Kimai;

use App\Enums\SyncAction;
use App\Models\OvertimeRecord;

/** Hasil satu sesi yang dicoba ditulis — dipakai sync maupun command regroup. */
final readonly class SessionWriteResult
{
    private function __construct(
        public SyncAction $action,
        public ?string $reason,
        public ?OvertimeRecord $record,
        public ?OvertimeSession $session,
    ) {}

    public static function created(OvertimeRecord $record, OvertimeSession $session): self
    {
        return new self(SyncAction::Created, null, $record, $session);
    }

    public static function updated(OvertimeRecord $record, OvertimeSession $session): self
    {
        return new self(SyncAction::Updated, null, $record, $session);
    }

    public static function unchanged(OvertimeRecord $record, OvertimeSession $session): self
    {
        return new self(SyncAction::Unchanged, null, $record, $session);
    }

    public static function skipped(string $reason, ?OvertimeRecord $record = null): self
    {
        return new self(SyncAction::Skipped, $reason, $record, null);
    }

    public function wasSkipped(): bool
    {
        return $this->action === SyncAction::Skipped;
    }
}
