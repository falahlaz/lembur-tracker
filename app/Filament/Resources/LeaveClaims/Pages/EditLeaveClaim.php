<?php

namespace App\Filament\Resources\LeaveClaims\Pages;

use App\Domain\Lembur\ClaimValidator;
use App\Domain\Lembur\LeaveAllocator;
use App\Domain\Lembur\LeaveTimesheetResult;
use App\Domain\Lembur\LeaveTimesheetSync;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Filament\Resources\LeaveClaims\LeaveClaimResource;
use App\Models\LeaveClaim;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Date;

class EditLeaveClaim extends EditRecord
{
    protected static string $resource = LeaveClaimResource::class;

    public function getTitle(): string
    {
        return 'Ubah Klaim';
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $type = $data['claim_type'] instanceof ClaimType
            ? $data['claim_type']
            : ClaimType::from($data['claim_type']);
        $date = Date::parse($data['claim_date']);

        $data['minutes_required'] = app(ClaimValidator::class)->minutesRequired($date, $type);
        $data['quota_weight'] = $type->quotaWeight();

        if (self::statusOf($data) === ClaimStatus::Submitted && blank($data['submitted_at'] ?? null)) {
            $data['submitted_at'] = now();
        }

        if ($type !== ClaimType::LateArrival) {
            $data['arrival_time'] = null;
        }

        return $data;
    }

    /**
     * BR-20 — status menentukan nasib saldo. Alokasi selalu disusun ulang dari
     * nol agar perubahan tanggal atau bentuk klaim tidak meninggalkan hold basi
     * yang menyandera saldo tanpa ada klaim yang memakainya.
     */
    /** CT-04 — lihat catatan di CreateLeaveClaim. */
    protected ?LeaveTimesheetResult $kimai = null;

    protected function afterSave(): void
    {
        /** @var LeaveClaim $claim */
        $claim = $this->getRecord();
        $allocator = app(LeaveAllocator::class);

        if ($claim->status->holdsBalance()) {
            $allocator->hold($claim);
        } elseif ($claim->status->releasesBalance() || $claim->status === ClaimStatus::Draft) {
            $allocator->release($claim);

            // Klaim yang dibatalkan tidak lagi perlu ditinjau (BR-23 selesai).
            if ($claim->needs_review) {
                $claim->update(['needs_review' => false]);
            }
        }

        // CT-04 dijalankan untuk SEMUA cabang, termasuk yang tadinya `return`
        // lebih awal: klaim yang dibatalkan justru yang entrinya harus ditarik
        // kembali dari Kimai.
        $this->kimai = app(LeaveTimesheetSync::class)->sync($claim);
    }

    protected function getSavedNotification(): ?Notification
    {
        /** @var LeaveClaim $claim */
        $claim = $this->getRecord()->refresh();

        $saldo = match (true) {
            $claim->status->holdsBalance() => 'Saldo tetap ditahan untuk klaim ini.',
            $claim->status->releasesBalance() => 'Saldo dikembalikan ke batch asalnya, tanggal hangusnya tidak berubah.',
            default => 'Status klaim diperbarui.',
        };

        return Notification::make()
            ->status($this->kimai !== null && ! $this->kimai->isClean() ? 'warning' : 'success')
            ->title('Klaim diperbarui')
            ->body(trim($saldo.' '.(string) $this->kimai?->pesan()));
    }

    /** Status bisa tiba sebagai enum atau string, tergantung jalur pengisian form. */
    protected static function statusOf(array $data): ?ClaimStatus
    {
        $status = $data['status'] ?? null;

        return $status instanceof ClaimStatus ? $status : ClaimStatus::tryFrom((string) $status);
    }
}
