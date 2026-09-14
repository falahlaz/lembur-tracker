<?php

namespace Tests;

use App\Domain\Lembur\ClaimValidator;
use App\Domain\Lembur\LeaveAllocator;
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
use Illuminate\Support\Facades\Http;

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

    /** User yang sudah punya koneksi Kimai tersimpan (F-11). */
    protected function kimaiUser(bool $rounding = false, string $token = 'tok-rahasia-ab12'): User
    {
        $user = $this->employee($rounding);
        $user->kimai_api_token = $token;
        $user->kimai_token_last4 = substr($token, -4);
        $user->kimai_token_valid_at = now();
        $user->save();

        return $user->refresh();
    }

    /**
     * Satu entri timesheet Kimai berbentuk payload asli instance.
     *
     * `begin`/`end` sengaja ditulis LENGKAP DENGAN OFFSET seperti respons nyata,
     * karena justru asimetri itu yang diuji SY-16.
     */
    protected function kimaiEntry(array $overrides = []): array
    {
        $begin = $overrides['begin'] ?? '2026-09-03T20:00:00+0700';
        $end = array_key_exists('end', $overrides) ? $overrides['end'] : '2026-09-03T22:00:00+0700';

        $duration = $overrides['duration'] ?? ($end === null
            ? 0
            : Carbon::parse($begin)->diffInSeconds(Carbon::parse($end)));

        return array_merge([
            'activity' => 9,
            'project' => 105,
            'user' => 145,
            'tags' => ['Overtime'],
            'id' => 182772,
            'begin' => $begin,
            'end' => $end,
            'duration' => (int) $duration,
            'break' => 0,
            'description' => "No Sprint - Release 9.4.0\n1. Support Regression Test",
            'rate' => 0,
            'internalRate' => 0,
            'exported' => false,
            'billable' => true,
            'metaFields' => [],
        ], $overrides);
    }

    /** @var array<int, array<string, mixed>> entri yang sedang dilayani fake Kimai */
    protected array $kimaiEntries = [];

    /**
     * Memalsukan /api/timesheets dengan paginasi yang benar.
     *
     * Sengaja memakai closure, bukan Http::sequence(): Http::fake() menumpuk
     * stub alih-alih menggantinya, sehingga sequence dari pemanggilan sebelumnya
     * akan tetap menang dan habis di tengah test yang melakukan sync dua kali.
     * Closure membaca $this->kimaiEntries, jadi memanggil helper ini lagi cukup
     * mengganti isinya.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    protected function fakeKimai(array $entries): void
    {
        $this->kimaiEntries = $entries;

        if ($this->kimaiFaked) {
            return;
        }

        $this->kimaiFaked = true;

        Http::fake(['*/api/timesheets*' => function ($request) {
            $size = (int) config('kimai.page_size');
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = max(1, (int) ($query['page'] ?? 1));

            return Http::response(
                array_slice($this->kimaiEntries, ($page - 1) * $size, $size),
                200,
            );
        }]);
    }

    protected bool $kimaiFaked = false;

    /** Klaim yang langsung menahan saldo (BR-20). */
    protected function submitClaim(User $user, string $date, ClaimType $type): LeaveClaim
    {
        $validator = app(ClaimValidator::class);
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

        app(LeaveAllocator::class)->hold($claim);

        return $claim->refresh();
    }
}
