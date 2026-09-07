<?php

namespace Tests;

use App\Domain\Lembur\OvertimeDayCalculator;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Enums\OvertimeStatus;
use App\Enums\Role;
use App\Models\LeaveClaim;
use App\Models\OvertimeRecord;
use App\Models\OvertimeRule;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seluruh sistem ini digerakkan tanggal — kedaluwarsa, cut-off, kuota bulanan.
     * Tanpa membekukan waktu, test yang hari ini hijau akan merah bulan depan
     * hanya karena jam dinding bergerak. Setiap test yang menyentuh saldo wajib
     * memanggil ini lebih dulu.
     */
    protected function freezeDate(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function baselineRule(array $overrides = []): OvertimeRule
    {
        return OvertimeRule::query()->create(array_merge([
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'tier1_min_minutes' => 240,
            'tier1_meal_amount' => 50_000,
            'tier1_leave_minutes' => 240,
            'tier2_min_minutes' => 480,
            'tier2_meal_amount' => 100_000,
            'tier2_leave_minutes' => 480,
            'cutoff_day' => 19,
            'expiry_months' => 1,
            'monthly_claim_quota_days' => 3.0,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
        ], $overrides));
    }

    protected function employee(bool $rounding = false): User
    {
        // refresh() penting: kolom ber-default di database belum ada di instance
        // hasil create(), dan strict mode akan melempar saat diakses.
        return tap(User::query()->create([
            'name' => 'Karyawan Uji',
            'email' => 'uji'.uniqid().'@lemburku.test',
            'password' => 'password',
            'role' => Role::Employee,
            'is_active' => true,
            'rounding_enabled' => $rounding,
        ]))->refresh();
    }

    /** Membuat record lembur lewat jalur normal, sehingga observer & kalkulator ikut jalan. */
    protected function logOvertime(
        User $user,
        string $date,
        string $start,
        string $end,
        OvertimeStatus $status = OvertimeStatus::Recorded,
    ): OvertimeRecord {
        $record = OvertimeRecord::query()->create([
            'user_id' => $user->id,
            'overtime_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'work_description' => 'Pekerjaan uji otomatis',
            'evidence_url' => 'https://onedrive.example.test/spl/123',
            'status' => $status,
        ]);

        return $record->refresh();
    }

    /** Klaim yang langsung menahan saldo (BR-20). */
    protected function submitClaim(User $user, string $date, ClaimType $type): LeaveClaim
    {
        $validator = app(\App\Domain\Lembur\ClaimValidator::class);
        $claimDate = Carbon::parse($date);

        $claim = LeaveClaim::query()->create([
            'user_id' => $user->id,
            'claim_date' => $date,
            'claim_type' => $type,
            'arrival_time' => $type === ClaimType::LateArrival ? '13:00:00' : null,
            'minutes_required' => $validator->minutesRequired($claimDate, $type),
            'quota_weight' => $type->quotaWeight(),
            'status' => ClaimStatus::Submitted,
            'submitted_at' => now(),
        ]);

        app(\App\Domain\Lembur\LeaveAllocator::class)->hold($claim);

        return $claim->refresh();
    }
}
