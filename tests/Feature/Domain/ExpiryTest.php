<?php

namespace Tests\Feature\Domain;

use App\Domain\Lembur\ExpiryCalculator;
use App\Models\LeaveBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BR-12, BR-13 — satuan saldo dan masa berlaku 1 bulan kalender. */
class ExpiryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function br_13_berlaku_satu_bulan_kalender_termasuk_tanggal_hangusnya(): void
    {
        $rule = $this->baselineRule();
        $calc = app(ExpiryCalculator::class);

        // Lembur 5 Maret → berlaku sampai DAN TERMASUK 5 April.
        $expires = $calc->expiryFor(Carbon::parse('2026-03-05'), $rule);
        $this->assertSame('2026-04-05', $expires->toDateString());

        $this->assertTrue($calc->isAliveOn($expires, Carbon::parse('2026-04-05')));
        $this->assertFalse($calc->isAliveOn($expires, Carbon::parse('2026-04-06')));
    }

    #[Test]
    public function br_13_clamping_akhir_bulan_tidak_melompati_bulan(): void
    {
        $rule = $this->baselineRule();
        $calc = app(ExpiryCalculator::class);

        // 31 Januari → 28 Februari (2026 bukan kabisat), bukan 3 Maret.
        $this->assertSame(
            '2026-02-28',
            $calc->expiryFor(Carbon::parse('2026-01-31'), $rule)->toDateString(),
        );

        // Tahun kabisat: 31 Januari 2028 → 29 Februari 2028.
        $this->assertSame(
            '2028-02-29',
            $calc->expiryFor(Carbon::parse('2028-01-31'), $rule)->toDateString(),
        );

        // 31 Maret → 30 April.
        $this->assertSame(
            '2026-04-30',
            $calc->expiryFor(Carbon::parse('2026-03-31'), $rule)->toDateString(),
        );
    }

    #[Test]
    public function br_13_masa_berlaku_tidak_diperpanjang_oleh_weekend(): void
    {
        $rule = $this->baselineRule();
        $calc = app(ExpiryCalculator::class);

        // 4 April 2026 jatuh hari Sabtu — tanggalnya tetap, tidak digeser ke Senin.
        $expires = $calc->expiryFor(Carbon::parse('2026-03-04'), $rule);

        $this->assertSame('2026-04-04', $expires->toDateString());
        $this->assertTrue($expires->isSaturday());
    }

    #[Test]
    public function br_12_batch_saldo_dibuat_dalam_satuan_menit(): void
    {
        $this->baselineRule();
        $user = $this->employee();

        $record = $this->logOvertime($user, '2026-03-05', '19:00', '23:30');   // tier 1

        $batch = LeaveBalance::query()->where('user_id', $user->id)->sole();

        $this->assertSame($record->id, $batch->overtime_record_id);
        $this->assertSame(240, $batch->earned_minutes);        // 4 jam, bukan "0,5 hari"
        $this->assertSame(0, $batch->consumed_minutes);
        $this->assertSame(0, $batch->held_minutes);
        $this->assertSame('2026-03-05', $batch->earned_date->toDateString());
        $this->assertSame('2026-04-05', $batch->expires_at->toDateString());
    }

    #[Test]
    public function br_12_lembur_delapan_jam_menghasilkan_batch_480_menit(): void
    {
        $this->baselineRule();
        $user = $this->employee();

        $this->logOvertime($user, '2026-03-05', '09:00', '19:00');   // 10 jam → tier 2

        $batch = LeaveBalance::query()->where('user_id', $user->id)->sole();
        $this->assertSame(480, $batch->earned_minutes);
    }
}
