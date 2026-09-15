<?php

namespace Tests\Feature\Domain;

use App\Domain\Kimai\KimaiTimesheet;
use App\Domain\Kimai\OvertimeSession;
use App\Domain\Kimai\SessionGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SY-23 — aturan jendela lembur, diuji langsung di pengelompokannya.
 *
 * Kimai membatasi satu timesheet maksimal 2 jam, jadi satu sesi lembur selalu datang
 * terpecah. Kelas inilah yang memutuskan pecahan mana milik sesi mana, dan salah di
 * sini berarti lembur mendarat di tanggal yang salah dengan tier yang salah.
 *
 * Tanggal sengaja dipilih sadar hari — lihat assertion hari di tiap test, supaya
 * kesalahan kalender ketahuan sebagai kegagalan test, bukan sebagai angka aneh.
 */
class SessionGrouperTest extends TestCase
{
    use RefreshDatabase;

    private const WEDNESDAY = '2026-09-16';

    private const THURSDAY = '2026-09-17';

    private const FRIDAY = '2026-09-18';

    private const SATURDAY = '2026-09-19';

    private const SUNDAY = '2026-09-20';

    private const MONDAY = '2026-09-21';

    private const TUESDAY = '2026-09-22';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-09-23');
        $this->baselineRule();
    }

    /** @return array<string, OvertimeSession> */
    private function group(array $slots, array $knownGroupKeys = []): array
    {
        return app(SessionGrouper::class)->group(
            array_map(fn (array $payload) => KimaiTimesheet::fromPayload($payload), $slots),
            $knownGroupKeys,
        );
    }

    #[Test]
    public function sy_23_jendela_weekday_menyatukan_seluruh_malam(): void
    {
        $this->assertTrue(Carbon::parse(self::WEDNESDAY)->isWednesday());

        $sessions = $this->group([
            $this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00'),
            $this->kimaiSlot(2, self::WEDNESDAY, '20:00', '22:00'),
            $this->kimaiSlot(3, self::WEDNESDAY, '22:00', '00:00'),
            $this->kimaiSlot(4, self::THURSDAY, '00:00', '02:00'),
        ]);

        $this->assertSame(['we:'.self::WEDNESDAY], array_keys($sessions));

        $session = $sessions['we:'.self::WEDNESDAY];

        // Inilah bug aslinya: dulu entri keempat mendarat di Kamis sebagai lembur
        // 2 jam sendiri, dan Rabu hanya dihitung 6 jam.
        $this->assertSame(self::WEDNESDAY, $session->anchorDate->toDateString());
        $this->assertSame('18:00', $session->startTime());
        $this->assertSame('02:00', $session->endTime());
        $this->assertSame(480, $session->durationMinutes());
        $this->assertSame([1, 2, 3, 4], $session->timesheetIds());
    }

    #[Test]
    public function sy_23_weekend_seluruh_hari_jadi_satu_sesi(): void
    {
        $this->assertTrue(Carbon::parse(self::SATURDAY)->isSaturday());

        $sessions = $this->group([
            $this->kimaiSlot(1, self::SATURDAY, '09:00', '11:00'),
            $this->kimaiSlot(2, self::SATURDAY, '13:00', '15:00'),
        ]);

        $this->assertSame(['wk:'.self::SATURDAY], array_keys($sessions));
        $this->assertSame('09:00', $sessions['wk:'.self::SATURDAY]->startTime());
        $this->assertSame('15:00', $sessions['wk:'.self::SATURDAY]->endTime());
    }

    #[Test]
    public function sy_23_jumat_malam_menyeberang_ke_sabtu_tetap_satu_sesi(): void
    {
        $sessions = $this->group([
            $this->kimaiSlot(1, self::FRIDAY, '18:00', '20:00'),
            $this->kimaiSlot(2, self::SATURDAY, '00:00', '03:00'),
        ]);

        // Jendela dibuka di hari kerja, jadi aturan weekday yang menang atas aturan
        // weekend — meskipun pecahan keduanya jatuh di hari Sabtu.
        $this->assertSame(['we:'.self::FRIDAY], array_keys($sessions));
        $this->assertSame(300, $sessions['we:'.self::FRIDAY]->durationMinutes());
    }

    #[Test]
    public function sy_23_minggu_malam_ke_senin_pecah_jadi_dua(): void
    {
        $sessions = $this->group([
            $this->kimaiSlot(1, self::SUNDAY, '20:00', '22:00'),
            $this->kimaiSlot(2, self::MONDAY, '00:00', '02:00'),
        ]);

        // Jendela dibuka di weekend, jadi yang lewat tengah malam jadi input sendiri.
        $this->assertSame(['wk:'.self::SUNDAY, 'ts:2'], array_keys($sessions));
        $this->assertSame(self::MONDAY, $sessions['ts:2']->anchorDate->toDateString());
    }

    #[Test]
    public function sy_23_sabtu_ke_minggu_juga_pecah(): void
    {
        $sessions = $this->group([
            $this->kimaiSlot(1, self::SATURDAY, '20:00', '22:00'),
            $this->kimaiSlot(2, self::SUNDAY, '00:00', '02:00'),
        ]);

        $this->assertSame(['wk:'.self::SATURDAY, 'wk:'.self::SUNDAY], array_keys($sessions));
    }

    #[Test]
    public function sy_23_entri_weekday_di_jam_kerja_berdiri_sendiri(): void
    {
        $sessions = $this->group([
            $this->kimaiSlot(1, self::TUESDAY, '14:00', '16:00'),
            $this->kimaiSlot(2, self::TUESDAY, '19:00', '21:00'),
        ]);

        // Yang jam 14:00 tidak ikut ditarik ke sesi malam di tanggal yang sama.
        $this->assertSame(['ts:1', 'we:'.self::TUESDAY], array_keys($sessions));
        $this->assertSame(self::TUESDAY, $sessions['ts:1']->anchorDate->toDateString());
    }

    #[Test]
    public function sy_23_entri_pagi_tanpa_pembuka_tidak_tertarik_ke_kemarin(): void
    {
        $sessions = $this->group([
            $this->kimaiSlot(1, self::THURSDAY, '07:00', '09:00'),
        ]);

        // Secara harfiah jam 07:00 memang di dalam jendela "Rabu 18:00 → Kamis 09:00",
        // tetapi Rabu malam tidak ada lembur sama sekali — tidak ada yang membuka
        // jendelanya, jadi lembur ini milik hari Kamis.
        $this->assertSame(['ts:1'], array_keys($sessions));
        $this->assertSame(self::THURSDAY, $sessions['ts:1']->anchorDate->toDateString());
    }

    #[Test]
    public function sy_23_pembuka_boleh_datang_dari_record_yang_sudah_tersimpan(): void
    {
        $sessions = $this->group(
            [$this->kimaiSlot(1, self::THURSDAY, '07:00', '09:00')],
            ['we:'.self::WEDNESDAY],
        );

        // Sync sebelumnya sudah menyimpan sesi Rabu malam dan tidak menariknya lagi.
        // Tanpa daftar itu, lembur dini hari ini akan lepas dari sesinya.
        $this->assertSame(['we:'.self::WEDNESDAY], array_keys($sessions));
    }

    #[Test]
    public function sy_23_sabtu_dini_hari_tanpa_pembuka_masuk_grup_sabtu(): void
    {
        $sessions = $this->group([
            $this->kimaiSlot(1, self::SATURDAY, '03:00', '06:00'),
        ]);

        $this->assertSame(['wk:'.self::SATURDAY], array_keys($sessions));
    }

    #[Test]
    public function sy_24_jeda_antar_entri_disimpan_sebagai_break(): void
    {
        $sessions = $this->group([
            $this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00'),
            $this->kimaiSlot(2, self::WEDNESDAY, '21:00', '23:00'),
        ]);

        $session = $sessions['we:'.self::WEDNESDAY];

        $this->assertSame(300, $session->spanMinutes());
        $this->assertSame(240, $session->durationMinutes());
        // Jeda satu jam tidak ikut dihitung sebagai lembur, tapi juga tidak hilang
        // tanpa jejak — ia yang menjelaskan kenapa 18:00–23:00 berdurasi 4 jam.
        $this->assertSame(60, $session->breakMinutes());
    }

    #[Test]
    public function sy_23_batas_jam_mengikuti_versi_aturan(): void
    {
        // Aturan baru berlaku sejak Senin: jam pulang digeser ke 17:00.
        $this->baselineRule([
            'effective_from' => self::MONDAY,
            'work_end_time' => '17:00:00',
        ]);

        $sessions = $this->group([
            $this->kimaiSlot(1, self::TUESDAY, '17:30', '19:30'),
        ]);

        // Dengan aturan lama (18:00) entri ini akan berdiri sendiri; dengan aturan
        // yang berlaku pada tanggalnya, ia membuka jendela lembur.
        $this->assertSame(['we:'.self::TUESDAY], array_keys($sessions));
    }
}
