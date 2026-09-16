<?php

namespace Tests\Feature\Console;

use App\Enums\ClaimType;
use App\Enums\OvertimeStatus;
use App\Enums\Source;
use App\Models\LeaveBalance;
use App\Models\OvertimeRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SY-23 — `lemburku:kimai:regroup` membereskan record warisan sync 1:1, termasuk
 * yang sudah di luar jangkauan fetch sehingga sync sendiri tidak akan pernah
 * menyentuhnya lagi.
 */
class RegroupKimaiRecordsTest extends TestCase
{
    use RefreshDatabase;

    private const WEDNESDAY = '2026-09-09';

    private const THURSDAY = '2026-09-10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-09-13');
        $this->baselineRule();
    }

    /** Record seperti yang dihasilkan sync lama: satu timesheet, satu record. */
    private function legacyRecord(User $user, int $id, string $date, string $start, string $end): OvertimeRecord
    {
        $record = OvertimeRecord::query()->create([
            'user_id' => $user->id,
            'overtime_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'work_description' => "Lembur dari Kimai #{$id}",
            'evidence_url' => "https://timesheet.codeoffice.net/en/timesheet/{$id}/edit",
            'status' => OvertimeStatus::Recorded,
            'source' => Source::Kimai,
            'kimai_timesheet_id' => $id,
            'evidence_needs_review' => true,
        ]);

        return $record->refresh();
    }

    #[Test]
    public function regroup_menggabungkan_lembur_yang_terpecah_lintas_tengah_malam(): void
    {
        $user = $this->employee();

        // Persis bentuk bug-nya: 8 jam lembur tersebar jadi empat record di dua
        // tanggal, masing-masing 2 jam dan tidak satu pun mencapai tier.
        $this->legacyRecord($user, 1, self::WEDNESDAY, '18:00', '20:00');
        $this->legacyRecord($user, 2, self::WEDNESDAY, '20:00', '22:00');
        $this->legacyRecord($user, 3, self::WEDNESDAY, '22:00', '00:00');
        $this->legacyRecord($user, 4, self::THURSDAY, '00:00', '02:00');

        $this->assertSame(4, OvertimeRecord::count());

        $this->artisan('lemburku:kimai:regroup')->assertSuccessful();

        $this->assertSame(1, OvertimeRecord::count());

        $record = OvertimeRecord::first();
        $this->assertSame(self::WEDNESDAY, $record->overtime_date->toDateString());
        $this->assertSame('18:00', substr((string) $record->start_time, 0, 5));
        $this->assertSame('02:00', substr((string) $record->end_time, 0, 5));
        $this->assertSame(480, $record->duration_raw_minutes);
        $this->assertSame([1, 2, 3, 4], $record->kimaiEntries->pluck('kimai_timesheet_id')->all());
        $this->assertSame('we:'.self::WEDNESDAY, $record->kimai_group_key);
    }

    #[Test]
    public function regroup_dry_run_tidak_menulis_apa_pun(): void
    {
        $user = $this->employee();
        $this->legacyRecord($user, 1, self::WEDNESDAY, '18:00', '20:00');
        $this->legacyRecord($user, 2, self::WEDNESDAY, '20:00', '22:00');

        $this->artisan('lemburku:kimai:regroup', ['--dry-run' => true])
            ->expectsOutputToContain('we:'.self::WEDNESDAY)
            ->assertSuccessful();

        $this->assertSame(2, OvertimeRecord::count());
        $this->assertNull(OvertimeRecord::first()->kimai_group_key);
    }

    #[Test]
    public function regroup_boleh_dijalankan_berkali_kali(): void
    {
        $user = $this->employee();
        $this->legacyRecord($user, 1, self::WEDNESDAY, '18:00', '20:00');
        $this->legacyRecord($user, 2, self::WEDNESDAY, '20:00', '22:00');

        $this->artisan('lemburku:kimai:regroup')->assertSuccessful();
        $first = OvertimeRecord::first();

        $this->artisan('lemburku:kimai:regroup')->assertSuccessful();

        $this->assertSame(1, OvertimeRecord::count());
        $this->assertSame($first->id, OvertimeRecord::first()->id);
    }

    #[Test]
    public function regroup_tidak_menyentuh_record_yang_sudah_diedit_user(): void
    {
        $user = $this->employee();
        $edited = $this->legacyRecord($user, 1, self::WEDNESDAY, '18:00', '20:00');
        $this->legacyRecord($user, 2, self::WEDNESDAY, '20:00', '22:00');

        $edited->work_description = 'Ditulis ulang oleh user';
        $edited->save();
        $this->assertTrue($edited->refresh()->locally_modified);

        $this->artisan('lemburku:kimai:regroup')
            ->expectsOutputToContain('ada perubahan lokal')
            ->assertSuccessful();

        // SY-14 — sekali disentuh manusia, seluruh sesinya dibiarkan utuh.
        $this->assertSame(2, OvertimeRecord::count());
        $this->assertSame('Ditulis ulang oleh user', $edited->refresh()->work_description);
    }

    #[Test]
    public function regroup_tidak_menghanguskan_saldo_yang_sudah_dipakai_klaim(): void
    {
        $user = $this->employee();

        // Empat jam di hari Rabu sudah menghasilkan saldo tier 1, dan saldonya sudah
        // ditahan sebuah klaim.
        $this->legacyRecord($user, 1, self::WEDNESDAY, '18:00', '22:00');
        $this->legacyRecord($user, 2, self::WEDNESDAY, '22:00', '00:00');

        $balance = LeaveBalance::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertGreaterThan(0, $balance->earned_minutes);

        $claim = $this->submitClaim($user, '2026-09-11', ClaimType::LateArrival);
        $this->assertGreaterThan(0, $balance->refresh()->held_minutes);

        $this->artisan('lemburku:kimai:regroup')->assertSuccessful();

        // BR-23 — batch tidak boleh lenyap atau di-void diam-diam, dan klaimnya tidak
        // boleh ikut ditandai perlu ditinjau hanya karena dua record dilebur.
        $this->assertDatabaseHas('leave_balances', ['id' => $balance->id]);
        $this->assertSame($balance->earned_minutes, $balance->refresh()->earned_minutes);
        $this->assertFalse($claim->refresh()->needs_review);
    }

    #[Test]
    public function regroup_hanya_menyentuh_user_yang_diminta(): void
    {
        $mine = $this->employee();
        $other = $this->employee();

        $this->legacyRecord($mine, 1, self::WEDNESDAY, '18:00', '20:00');
        $this->legacyRecord($mine, 2, self::WEDNESDAY, '20:00', '22:00');
        $this->legacyRecord($other, 3, self::WEDNESDAY, '18:00', '20:00');
        $this->legacyRecord($other, 4, self::WEDNESDAY, '20:00', '22:00');

        $this->artisan('lemburku:kimai:regroup', ['--user' => $mine->email])->assertSuccessful();

        $this->assertSame(1, OvertimeRecord::where('user_id', $mine->id)->count());
        $this->assertSame(2, OvertimeRecord::where('user_id', $other->id)->count());
    }
}
