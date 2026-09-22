<?php

namespace Tests\Unit\Domain;

use App\Domain\Lembur\LeaveDayPlanner;
use App\Domain\Lembur\LeaveSlot;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** CT-03 — menit cuti menjadi slot jam; angkanya harus persis, bukan kira-kira. */
class LeaveDayPlannerTest extends TestCase
{
    private LeaveDayPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new LeaveDayPlanner;
    }

    /** @return array<int, string> */
    private function labels(int $minutes): array
    {
        return array_map(
            fn (LeaveSlot $slot) => $slot->label(),
            $this->planner->slots($minutes),
        );
    }

    #[Test]
    public function setengah_hari_240_menit_menghasilkan_tiga_slot_sampai_jam_14(): void
    {
        $this->assertSame(
            ['09:00–11:00', '11:00–12:00', '13:00–14:00'],
            $this->labels(240),
        );
    }

    #[Test]
    public function sehari_penuh_480_menit_menghasilkan_lima_slot_sampai_jam_18(): void
    {
        $this->assertSame(
            ['09:00–11:00', '11:00–12:00', '13:00–14:00', '14:00–16:00', '16:00–18:00'],
            $this->labels(480),
        );
    }

    #[Test]
    public function jam_istirahat_tidak_pernah_masuk_slot(): void
    {
        foreach ($this->planner->slots(480) as $slot) {
            // 12:00 = menit ke-720, 13:00 = menit ke-780.
            $this->assertTrue(
                $slot->endMinute <= 720 || $slot->startMinute >= 780,
                "Slot {$slot->label()} menyentuh jam istirahat 12:00–13:00.",
            );
        }
    }

    #[Test]
    public function total_slot_selalu_sama_dengan_menit_yang_diminta(): void
    {
        foreach ([60, 120, 240, 300, 420, 480] as $menit) {
            $total = array_sum(array_map(
                fn (LeaveSlot $slot) => $slot->durationMinutes(),
                $this->planner->slots($menit),
            ));

            $this->assertSame($menit, $total, "Total slot untuk {$menit} menit meleset.");
        }
    }

    #[Test]
    public function sisa_yang_lebih_pendek_dari_bloknya_memotong_blok_terakhir(): void
    {
        // 300 menit = 120 + 60 + 60, lalu 60 sisa dari blok 14:00–16:00.
        $this->assertSame(
            ['09:00–11:00', '11:00–12:00', '13:00–14:00', '14:00–15:00'],
            $this->labels(300),
        );
    }

    #[Test]
    public function menit_melebihi_satu_hari_kerja_dibatasi_lima_slot_dan_dilaporkan(): void
    {
        $this->assertCount(5, $this->planner->slots(600));
        $this->assertSame(480, $this->planner->capacityMinutes());
        $this->assertSame(120, $this->planner->overflowMinutes(600));
        $this->assertSame(0, $this->planner->overflowMinutes(480));
    }

    #[Test]
    public function nol_menit_tidak_menghasilkan_slot(): void
    {
        $this->assertSame([], $this->planner->slots(0));
        $this->assertSame([], $this->planner->slots(-30));
    }

    #[Test]
    public function slot_dibangun_di_zona_kimai_bukan_zona_aplikasi(): void
    {
        // Test berjalan dengan APP_TIMEZONE=UTC; tanpa anchor zona Kimai, jam 09:00
        // akan berakhir sebagai 09:00 UTC alias 16:00 WIB.
        $this->assertSame('Asia/Jakarta', config('kimai.timezone'));

        $date = CarbonImmutable::parse('2026-04-01', 'UTC');
        $slot = $this->planner->slots(240)[0];

        $this->assertSame('2026-04-01T09:00:00+0700', $slot->beginAt($date)->format('Y-m-d\TH:i:sO'));
        $this->assertSame('2026-04-01T11:00:00+0700', $slot->endAt($date)->format('Y-m-d\TH:i:sO'));
    }
}
