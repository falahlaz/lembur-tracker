<?php

namespace Tests\Feature\Domain;

use App\Domain\Lembur\LeaveDayPlanner;
use App\Domain\Lembur\LeaveTimesheetSync;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Enums\LeaveTimesheetStatus;
use App\Models\LeaveClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** CT-01 s/d CT-05 — klaim cuti pengganti yang diajukan menulis entri cuti di Kimai. */
class LeaveTimesheetTest extends TestCase
{
    use RefreshDatabase;

    private LeaveTimesheetSync $sync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();

        // Ditambahkan DI SINI, bukan di TestCase: fixture activity dipakai bersama
        // oleh test sync katalog yang menghitung isinya, dan menambah satu baris
        // di sana membuat delapan test lain gagal karena cacahnya berubah.
        $this->kimaiActivities[] = ['id' => 41, 'name' => '00_ANNUAL_LEAVE', 'project' => null];

        $this->fakeKimai([]);
        $this->sync = app(LeaveTimesheetSync::class);
    }

    /** User bertoken Kimai, dengan saldo cukup untuk beberapa hari penuh. */
    private function pemilikSaldo(int $hari = 3): User
    {
        $user = $this->kimaiUser();

        for ($i = 0; $i < $hari; $i++) {
            $this->logOvertime($user, Carbon::parse('2026-03-01')->addDays($i)->toDateString(), '09:00', '19:00');
        }

        return $user->refresh();
    }

    private function ajukan(User $user, string $tanggal, ClaimType $type): LeaveClaim
    {
        $claim = $this->submitClaim($user, $tanggal, $type);
        $this->sync->sync($claim);

        return $claim->refresh();
    }

    // ── Bentuk entri ──────────────────────────────────────────────────────────

    #[Test]
    public function ct_04_klaim_sehari_penuh_membuat_lima_timesheet_sampai_jam_18(): void
    {
        $claim = $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::FullDay);

        $bodies = $this->kimaiPostBodies();

        $this->assertCount(5, $bodies);
        $this->assertSame('2026-04-01T09:00:00+0700', $bodies[0]['begin']);
        $this->assertSame('2026-04-01T18:00:00+0700', $bodies[4]['end']);
        $this->assertSame(5, $claim->timesheets()->where('status', LeaveTimesheetStatus::Posted->value)->count());
    }

    #[Test]
    public function ct_04_klaim_setengah_hari_membuat_tiga_timesheet_berhenti_jam_14(): void
    {
        $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::LateArrival);

        $bodies = $this->kimaiPostBodies();

        $this->assertCount(3, $bodies);
        $this->assertSame(
            [
                ['2026-04-01T09:00:00+0700', '2026-04-01T11:00:00+0700'],
                ['2026-04-01T11:00:00+0700', '2026-04-01T12:00:00+0700'],
                ['2026-04-01T13:00:00+0700', '2026-04-01T14:00:00+0700'],
            ],
            array_map(fn (array $b) => [$b['begin'], $b['end']], $bodies),
        );
    }

    #[Test]
    public function ct_04_entri_cuti_tidak_pernah_membawa_tag(): void
    {
        // Pagar anti-lembur-hantu: entri bertag `Overtime` ditarik kembali oleh
        // sync menjadi catatan lembur, yang berarti cuti menambah saldo.
        $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::FullDay);

        foreach ($this->kimaiPostBodies() as $body) {
            $this->assertArrayNotHasKey('tags', $body);
        }
    }

    #[Test]
    public function ct_04_payload_hanya_berisi_lima_field_yang_diizinkan_kimai(): void
    {
        $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::FullDay);

        foreach ($this->kimaiPostBodies() as $body) {
            $this->assertSame(
                ['begin', 'end', 'project', 'activity', 'description'],
                array_keys($body),
            );
            $this->assertSame(105, $body['project']);
            $this->assertSame(41, $body['activity']);
        }
    }

    #[Test]
    public function ct_03_jumlah_slot_mengikuti_aturan_berversi_bukan_bentuk_klaim(): void
    {
        // Aturan yang diubah harus terbawa sampai ke Kimai; kalau slot dikunci
        // pada ClaimType, saldo dan timesheet akan menyebut angka berbeda.
        $this->baselineRule(['tier2_leave_minutes' => 420]);

        $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::FullDay);

        $bodies = $this->kimaiPostBodies();

        $this->assertCount(5, $bodies);
        $this->assertSame('2026-04-01T17:00:00+0700', $bodies[4]['end']);
    }

    #[Test]
    public function ct_03_pola_slot_menutup_persis_jendela_kerja_pada_aturan_baseline(): void
    {
        $rule = $this->baselineRule();
        $planner = app(LeaveDayPlanner::class);

        $this->assertSame('09:00', substr((string) $rule->work_start_time, 0, 5));
        $this->assertSame('18:00', substr((string) $rule->work_end_time, 0, 5));
        $this->assertSame($rule->minutesForFullDay(), $planner->capacityMinutes());
    }

    // ── Siklus status ─────────────────────────────────────────────────────────

    #[Test]
    public function ct_04_klaim_draft_tidak_mengirim_apa_pun(): void
    {
        $user = $this->pemilikSaldo();

        $claim = LeaveClaim::query()->create([
            'user_id' => $user->id,
            'claim_date' => '2026-04-01',
            'claim_type' => ClaimType::FullDay,
            'minutes_required' => 480,
            'quota_weight' => 1.0,
            'status' => ClaimStatus::Draft,
        ]);

        $this->sync->sync($claim);

        $this->assertSame([], $this->kimaiPostBodies());
        $this->assertSame(0, $claim->timesheets()->count());
    }

    #[Test]
    public function ct_04_membatalkan_klaim_menghapus_seluruh_entri_dari_kimai(): void
    {
        $claim = $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::FullDay);
        $ids = $claim->timesheets()->orderBy('begin_at')->pluck('kimai_timesheet_id')->all();

        $claim->update(['status' => ClaimStatus::Cancelled]);
        $this->sync->sync($claim->refresh());

        $this->assertSame($ids, $this->kimaiDeletedIds());
        $this->assertSame(5, $claim->timesheets()->where('status', LeaveTimesheetStatus::Deleted->value)->count());
    }

    #[Test]
    public function ct_04_menolak_klaim_juga_menghapus_entri_dari_kimai(): void
    {
        $claim = $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::LateArrival);

        $claim->update(['status' => ClaimStatus::Rejected]);
        $this->sync->sync($claim->refresh());

        $this->assertCount(3, $this->kimaiDeletedIds());
    }

    #[Test]
    public function ct_04_menyetujui_klaim_tidak_mengirim_ulang_entri_yang_sudah_ada(): void
    {
        $claim = $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::FullDay);

        $claim->update(['status' => ClaimStatus::Approved]);
        $this->sync->sync($claim->refresh());

        $this->assertCount(5, $this->kimaiPostBodies());
        $this->assertSame([], $this->kimaiDeletedIds());
    }

    #[Test]
    public function ct_04_mengajukan_ulang_tidak_menggandakan_entri(): void
    {
        $claim = $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::FullDay);

        $this->sync->sync($claim);
        $this->sync->sync($claim);

        $this->assertCount(5, $this->kimaiPostBodies());
        $this->assertSame(5, $claim->timesheets()->count());
    }

    #[Test]
    public function ct_04_mengecilkan_klaim_hanya_menghapus_slot_sore(): void
    {
        $claim = $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::FullDay);

        $pagi = $claim->timesheets()->orderBy('begin_at')->limit(3)->pluck('kimai_timesheet_id')->all();
        $sore = $claim->timesheets()->orderBy('begin_at')->skip(3)->limit(2)->pluck('kimai_timesheet_id')->all();

        $claim->update(['claim_type' => ClaimType::LateArrival, 'minutes_required' => 240]);
        $this->sync->sync($claim->refresh());

        // Slot pagi tidak tersentuh: id Kimai-nya harus persis sama seperti semula.
        $this->assertSame($sore, $this->kimaiDeletedIds());
        $this->assertCount(5, $this->kimaiPostBodies());
        $this->assertSame(
            $pagi,
            $claim->timesheets()->kept()->orderBy('begin_at')->pluck('kimai_timesheet_id')->all(),
        );
    }

    #[Test]
    public function ct_04_mengubah_tanggal_memindahkan_seluruh_slot(): void
    {
        $claim = $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::LateArrival);

        $claim->update(['claim_date' => '2026-04-08']);
        $this->sync->sync($claim->refresh());

        $this->assertCount(3, $this->kimaiDeletedIds());

        $bodies = $this->kimaiPostBodies();
        $this->assertCount(6, $bodies);
        $this->assertSame('2026-04-08T09:00:00+0700', $bodies[3]['begin']);
    }

    #[Test]
    public function ct_05_menghapus_klaim_menarik_entrinya_dari_kimai(): void
    {
        $claim = $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::LateArrival);

        $claim->delete();

        $this->assertCount(3, $this->kimaiDeletedIds());
    }

    // ── Kegagalan ─────────────────────────────────────────────────────────────

    #[Test]
    public function ct_04_activity_cuti_yang_tidak_ada_tidak_menggagalkan_klaim(): void
    {
        config(['kimai.leave_activity' => '00_TIDAK_ADA']);

        $claim = $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::FullDay);
        $result = $this->sync->sync($claim);

        $this->assertSame([], $this->kimaiPostBodies());
        $this->assertSame(ClaimStatus::Submitted, $claim->refresh()->status);
        $this->assertSame(0, $claim->timesheets()->count());
        $this->assertStringContainsString('00_TIDAK_ADA', (string) $result->pesan());
        $this->assertStringContainsString('105', (string) $result->pesan());
    }

    #[Test]
    public function ct_04_nama_activity_yang_ambigu_tidak_ditebak(): void
    {
        $this->kimaiActivities[] = ['id' => 77, 'name' => '00_ANNUAL_LEAVE', 'project' => 105];

        $claim = $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::FullDay);
        $result = $this->sync->sync($claim);

        $this->assertSame([], $this->kimaiPostBodies());
        $this->assertStringContainsString('lebih dari satu', (string) $result->pesan());
    }

    #[Test]
    public function ct_04_user_tanpa_api_key_tidak_menyentuh_jaringan(): void
    {
        $user = $this->employee();
        $this->logOvertime($user, '2026-03-01', '09:00', '19:00');

        $claim = $this->submitClaim($user->refresh(), '2026-04-01', ClaimType::FullDay);
        $result = $this->sync->sync($claim);

        Http::assertNothingSent();
        $this->assertSame(0, $claim->timesheets()->count());
        $this->assertNull($result->pesan());
    }

    #[Test]
    public function ct_04_kimai_mati_meninggalkan_slot_pending_untuk_dikirim_ulang(): void
    {
        $this->failKimaiPostAt(3, 503);

        $claim = $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::FullDay);

        $this->assertSame(2, $claim->timesheets()->where('status', LeaveTimesheetStatus::Posted->value)->count());
        $this->assertSame(3, $claim->timesheets()->pending()->count());

        // Kirim ulang hanya mengirim sisanya, tidak pernah yang sudah masuk.
        $this->kimaiPostHandler = null;
        $this->sync->sync($claim);

        // 3 permintaan pertama (dua masuk, satu dijawab 503) + 3 slot sisanya.
        $this->assertCount(6, $this->kimaiPostBodies());
        $this->assertSame(5, $claim->timesheets()->where('status', LeaveTimesheetStatus::Posted->value)->count());
    }

    #[Test]
    public function ct_04_token_ditolak_menandai_koneksi_tidak_valid_dan_berhenti(): void
    {
        $this->failKimaiPostAt(1, 401);

        $user = $this->pemilikSaldo();
        $claim = $this->ajukan($user, '2026-04-01', ClaimType::FullDay);

        $this->assertCount(1, $this->kimaiPostBodies());
        $this->assertNull($user->refresh()->kimai_token_valid_at);
    }

    #[Test]
    public function ct_04_satu_slot_yang_ditolak_400_tidak_menghentikan_sisanya(): void
    {
        $this->failKimaiPostAt(2, 400, ['message' => 'Timesheet tumpang tindih']);

        $claim = $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::FullDay);

        $this->assertCount(5, $this->kimaiPostBodies());
        $this->assertSame(4, $claim->timesheets()->where('status', LeaveTimesheetStatus::Posted->value)->count());

        $gagal = $claim->timesheets()->where('status', LeaveTimesheetStatus::Failed->value)->first();
        $this->assertNotNull($gagal);
        $this->assertStringContainsString('tumpang tindih', (string) $gagal->error_message);
    }

    #[Test]
    public function ct_04_delete_404_dianggap_selesai(): void
    {
        $claim = $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::LateArrival);

        $this->failKimaiDeleteAt(1, 404);

        $claim->update(['status' => ClaimStatus::Cancelled]);
        $this->sync->sync($claim->refresh());

        $this->assertSame(3, $claim->timesheets()->where('status', LeaveTimesheetStatus::Deleted->value)->count());
    }

    #[Test]
    public function ct_04_delete_yang_gagal_5xx_meninggalkan_barisnya_untuk_dicoba_lagi(): void
    {
        $claim = $this->ajukan($this->pemilikSaldo(), '2026-04-01', ClaimType::LateArrival);

        $this->failKimaiDeleteAt(1, 503);

        $claim->update(['status' => ClaimStatus::Cancelled]);
        $this->sync->sync($claim->refresh());

        $this->assertSame(3, $claim->timesheets()->where('status', LeaveTimesheetStatus::Posted->value)->count());

        $this->kimaiDeleteHandler = null;
        $this->sync->sync($claim);

        $this->assertSame(3, $claim->timesheets()->where('status', LeaveTimesheetStatus::Deleted->value)->count());
    }

    #[Test]
    public function ct_04_entri_dikirim_dengan_token_pemilik_klaim_bukan_token_admin(): void
    {
        // SY-01: permintaan tanpa parameter `user` masuk ke timesheet PEMILIK
        // TOKEN, jadi token admin akan menempelkan cuti karyawan ke hari admin.
        $admin = $this->kimaiAdmin();
        $karyawan = $this->pemilikSaldo();

        $this->actingAs($admin);
        $this->ajukan($karyawan, '2026-04-01', ClaimType::LateArrival);

        Http::assertSent(fn ($request) => $request->method() !== 'POST'
            || $request->hasHeader('Authorization', 'Bearer tok-rahasia-ab12'));
    }
}
