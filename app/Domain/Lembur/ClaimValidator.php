<?php

namespace App\Domain\Lembur;

use App\Enums\ClaimType;
use App\Models\LeaveClaim;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonInterface;

/**
 * BR-19, BR-21, BR-22 — validasi klaim cuti pengganti.
 *
 * Urutannya TETAP dan disengaja (BR-22):
 *   1. tanggal bentrok dengan klaim aktif lain   (BR-21)
 *   2. kuota bulan kalender                      (BR-19)
 *   3. kecukupan saldo aktif pada tanggal cuti   (BR-16)
 *
 * Yang dikembalikan adalah pemeriksaan PERTAMA yang gagal. Tanpa urutan yang
 * ditetapkan, klaim di tanggal bentrok yang saldonya juga habis akan memunculkan
 * "saldo tidak cukup" — pesan menyesatkan, karena menambah saldo tidak
 * menyelesaikan apa pun; yang salah tanggalnya.
 */
class ClaimValidator
{
    public function __construct(
        private readonly RuleResolver $rules,
        private readonly LeaveAllocator $allocator,
    ) {}

    public function validate(
        User $user,
        CarbonInterface $claimDate,
        ClaimType $type,
        ?int $ignoreClaimId = null,
    ): ClaimValidationResult {
        if (($clash = $this->checkOverlap($user, $claimDate, $ignoreClaimId))->failed()) {
            return $clash;
        }

        if (($quota = $this->checkQuota($user, $claimDate, $type, $ignoreClaimId))->failed()) {
            return $quota;
        }

        return $this->checkBalance($user, $claimDate, $type, $ignoreClaimId);
    }

    /** BR-21 — dua klaim aktif tidak boleh berada di tanggal yang sama. */
    public function checkOverlap(User $user, CarbonInterface $claimDate, ?int $ignoreClaimId = null): ClaimValidationResult
    {
        $exists = LeaveClaim::query()
            ->where('user_id', $user->id)
            ->whereDate('claim_date', $claimDate->toDateString())
            ->active()
            ->when($ignoreClaimId, fn ($q) => $q->whereKeyNot($ignoreClaimId))
            ->exists();

        return $exists
            ? ClaimValidationResult::fail('BR-21', 'Sudah ada klaim di tanggal ini.')
            : ClaimValidationResult::pass();
    }

    /** BR-19 — maksimal 3 hari per BULAN KALENDER; penuh = 1,0 dan datang siang = 0,5. */
    public function checkQuota(
        User $user,
        CarbonInterface $claimDate,
        ClaimType $type,
        ?int $ignoreClaimId = null,
    ): ClaimValidationResult {
        $quota = (float) $this->rules->forDate($claimDate)->monthly_claim_quota_days;
        $used = $this->quotaUsedIn($user, $claimDate, $ignoreClaimId);
        $weight = $type->quotaWeight();

        if ($used + $weight <= $quota + 1e-9) {
            return ClaimValidationResult::pass();
        }

        $month = ucfirst($claimDate->translatedFormat('F'));

        return ClaimValidationResult::fail('BR-19', sprintf(
            'Kuota klaim %s sudah penuh (%s dari %s hari). Coba ajukan untuk bulan berikutnya — tapi cek dulu masa berlaku saldonya.',
            $month,
            $this->trimNumber($used),
            $this->trimNumber($quota),
        ));
    }

    /** BR-16 — saldo diuji terhadap tanggal cuti diambil, bukan tanggal pengajuan. */
    public function checkBalance(
        User $user,
        CarbonInterface $claimDate,
        ClaimType $type,
        ?int $ignoreClaimId = null,
    ): ClaimValidationResult {
        $required = $this->minutesRequired($claimDate, $type);
        $plan = $this->allocator->plan($user, $claimDate, $required, $ignoreClaimId);

        if ($plan->isSatisfiable()) {
            return ClaimValidationResult::pass();
        }

        return ClaimValidationResult::fail('BR-16', sprintf(
            'Saldo kamu %s, kurang %s untuk %s. Pilih "datang lebih siang", atau catat lembur yang belum diinput.',
            Format::durasi($plan->availableMinutes),
            Format::durasi($plan->shortfallMinutes()),
            mb_strtolower($type->getLabel()),
        ));
    }

    /** BR-18 — menit yang dikonsumsi tiap bentuk klaim, dari versi aturan yang berlaku. */
    public function minutesRequired(CarbonInterface $claimDate, ClaimType $type): int
    {
        $rule = $this->rules->forDate($claimDate);

        return $type === ClaimType::FullDay
            ? $rule->minutesForFullDay()
            : $rule->minutesForLateArrival();
    }

    /** Bobot kuota yang sudah terpakai pada bulan kalender tanggal cuti. */
    public function quotaUsedIn(User $user, CarbonInterface $claimDate, ?int $ignoreClaimId = null): float
    {
        return (float) LeaveClaim::query()
            ->where('user_id', $user->id)
            ->inCalendarMonth($claimDate->year, $claimDate->month)
            ->active()
            ->when($ignoreClaimId, fn ($q) => $q->whereKeyNot($ignoreClaimId))
            ->sum('quota_weight');
    }

    public function quotaLimitFor(CarbonInterface $claimDate): float
    {
        return (float) $this->rules->forDate($claimDate)->monthly_claim_quota_days;
    }

    /** "1,5" dan "3" — bukan "1.5" dan "3.0". */
    private function trimNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    }
}
