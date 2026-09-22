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
use App\Support\Format;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;

class CreateLeaveClaim extends CreateRecord
{
    protected static string $resource = LeaveClaimResource::class;

    public function getTitle(): string
    {
        return 'Ajukan Klaim';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] ??= Auth::id();

        $type = $data['claim_type'] instanceof ClaimType
            ? $data['claim_type']
            : ClaimType::from($data['claim_type']);
        $date = Date::parse($data['claim_date']);

        // Menit dan bobot diturunkan dari versi aturan, bukan dari input user.
        $data['minutes_required'] = app(ClaimValidator::class)->minutesRequired($date, $type);
        $data['quota_weight'] = $type->quotaWeight();

        if (self::statusOf($data) === ClaimStatus::Submitted) {
            $data['submitted_at'] = now();
        }

        if ($type !== ClaimType::LateArrival) {
            $data['arrival_time'] = null;
        }

        return $data;
    }

    /**
     * CT-04 — hasil pengiriman ke Kimai, ditahan di sini supaya notifikasinya
     * bisa mengatakan apa yang BENAR-BENAR terjadi, bukan sekadar "tersimpan".
     */
    protected ?LeaveTimesheetResult $kimai = null;

    /**
     * BR-20 — saldo ditahan begitu klaim mencapai status "diajukan".
     *
     * CT-04 menempel di sini, sejajar dengan hold(), bukan lewat observer model:
     * menahan saldo dan menulis timesheet adalah dua akibat dari keputusan yang
     * sama, dan `LeaveClaim::saved` juga menyala dari perintah harian dan dari
     * rekonsiliasi saldo — tempat yang tidak boleh mengirim apa pun ke Kimai.
     */
    protected function afterCreate(): void
    {
        /** @var LeaveClaim $claim */
        $claim = $this->getRecord();

        if ($claim->status->holdsBalance()) {
            app(LeaveAllocator::class)->hold($claim);
        }

        $this->kimai = app(LeaveTimesheetSync::class)->sync($claim);
    }

    protected function getCreatedNotification(): ?Notification
    {
        /** @var LeaveClaim $claim */
        $claim = $this->getRecord()->refresh();

        $saldo = $claim->status->holdsBalance()
            ? Format::durasi($claim->minutes_required).' saldo sudah ditahan untuk klaim ini.'
            : 'Masih berstatus draft — saldo belum ditahan.';

        $kimai = $this->kimai?->pesan();

        return Notification::make()
            ->status($this->kimai !== null && ! $this->kimai->isClean() ? 'warning' : 'success')
            ->title('Klaim '.Format::tanggalRingkas($claim->claim_date).' tersimpan')
            ->body(trim($saldo.' '.(string) $kimai));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /** Status bisa tiba sebagai enum atau string, tergantung jalur pengisian form. */
    protected static function statusOf(array $data): ?ClaimStatus
    {
        $status = $data['status'] ?? null;

        return $status instanceof ClaimStatus ? $status : ClaimStatus::tryFrom((string) $status);
    }
}
