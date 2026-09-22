<?php

namespace Tests\Feature\Filament;

use App\Domain\Lembur\LeaveTimesheetSync;
use App\Enums\BalanceStatus;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Enums\LeaveTimesheetStatus;
use App\Filament\Resources\LeaveClaims\Pages\CreateLeaveClaim;
use App\Filament\Resources\LeaveClaims\Pages\EditLeaveClaim;
use App\Filament\Resources\LeaveClaims\Pages\ListLeaveClaims;
use App\Filament\Resources\LeaveClaims\Tables\LeaveClaimsTable;
use App\Models\LeaveBalance;
use App\Models\LeaveClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** F-04 / F-06 — form klaim, panel alokasi, dan siklus hold/release. */
class LeaveClaimResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
    }

    #[Test]
    public function panel_alokasi_menampilkan_batch_yang_akan_terpakai(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-05', '19:00', '23:30');   // 4 jam, hangus 5 Apr
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');   // 8 jam, hangus 12 Apr

        Livewire::test(CreateLeaveClaim::class)
            ->fillForm([
                'claim_type' => ClaimType::FullDay->value,
                'claim_date' => '2026-04-01',
            ])
            // FIFO memecah 4 jam dari batch pertama + 4 jam dari batch kedua.
            ->assertSee('dari lembur 5 Mar')
            ->assertSee('dari lembur 12 Mar')
            ->assertSee('habis')
            ->assertSee('Total 8 jam')
            ->assertSee('sisa saldo 4 jam')
            ->assertSee('Kuota bulan April');
    }

    #[Test]
    public function menyimpan_klaim_diajukan_menahan_saldo(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');   // 8 jam

        Livewire::test(CreateLeaveClaim::class)
            ->fillForm([
                'claim_type' => ClaimType::FullDay->value,
                'claim_date' => '2026-04-01',
                'status' => ClaimStatus::Submitted->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $claim = LeaveClaim::query()->sole();
        $this->assertSame(480, $claim->minutes_required);
        $this->assertSame(1.0, $claim->quota_weight);
        $this->assertNotNull($claim->submitted_at);

        $batch = LeaveBalance::query()->sole();
        $this->assertSame(480, $batch->held_minutes);
        $this->assertCount(1, $claim->allocations);
    }

    #[Test]
    public function klaim_draft_belum_menahan_saldo(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');

        Livewire::test(CreateLeaveClaim::class)
            ->fillForm([
                'claim_type' => ClaimType::FullDay->value,
                'claim_date' => '2026-04-01',
                'status' => ClaimStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, LeaveBalance::query()->sole()->held_minutes);
    }

    #[Test]
    public function form_menolak_klaim_saat_saldo_tidak_cukup(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-12', '19:00', '23:30');   // hanya 4 jam

        Livewire::test(CreateLeaveClaim::class)
            ->fillForm([
                'claim_type' => ClaimType::FullDay->value,           // butuh 8 jam
                'claim_date' => '2026-04-01',
                'status' => ClaimStatus::Submitted->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['claim_date']);

        $this->assertSame(0, LeaveClaim::query()->count());
    }

    #[Test]
    public function form_menolak_tanggal_yang_sudah_dipakai_klaim_lain(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-10', '09:00', '19:00');
        $this->logOvertime($user, '2026-03-11', '09:00', '19:00');
        $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);

        Livewire::test(CreateLeaveClaim::class)
            ->fillForm([
                'claim_type' => ClaimType::FullDay->value,
                'claim_date' => '2026-04-01',
                'status' => ClaimStatus::Submitted->value,
            ])
            ->call('create')
            // BR-22 — pesan yang muncul soal TANGGAL, bukan saldo.
            ->assertHasFormErrors(['claim_date' => 'Sudah ada klaim di tanggal ini.']);
    }

    #[Test]
    public function membatalkan_klaim_mengembalikan_saldo(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');
        $claim = $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);

        $expiryBefore = LeaveBalance::query()->sole()->expires_at->toDateString();

        Livewire::test(EditLeaveClaim::class, ['record' => $claim->getKey()])
            ->fillForm(['status' => ClaimStatus::Cancelled->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $batch = LeaveBalance::query()->sole();
        $this->assertSame(0, $batch->held_minutes);
        $this->assertSame(BalanceStatus::Active, $batch->status);
        // Tanggal hangus tidak bergeser (BR-20).
        $this->assertSame($expiryBefore, $batch->expires_at->toDateString());
        $this->assertCount(0, $claim->refresh()->allocations);
    }

    #[Test]
    public function ct_04_mengajukan_klaim_lewat_halaman_membuat_timesheet_cuti(): void
    {
        $user = $this->kimaiUser();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');   // 8 jam
        $this->kimaiActivities[] = ['id' => 41, 'name' => '00_ANNUAL_LEAVE', 'project' => null];
        $this->fakeKimai([]);

        Livewire::test(CreateLeaveClaim::class)
            ->fillForm([
                'claim_type' => ClaimType::FullDay->value,
                'claim_date' => '2026-04-01',
                'status' => ClaimStatus::Submitted->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $claim = LeaveClaim::query()->sole();

        $this->assertCount(5, $this->kimaiPostBodies());
        $this->assertSame(
            5,
            $claim->timesheets()->where('status', LeaveTimesheetStatus::Posted->value)->count(),
        );
    }

    #[Test]
    public function ct_04_membatalkan_klaim_lewat_halaman_menghapus_timesheet_cuti(): void
    {
        $user = $this->kimaiUser();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');
        $this->kimaiActivities[] = ['id' => 41, 'name' => '00_ANNUAL_LEAVE', 'project' => null];
        $this->fakeKimai([]);

        $claim = $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);
        app(LeaveTimesheetSync::class)->sync($claim);

        Livewire::test(EditLeaveClaim::class, ['record' => $claim->getKey()])
            ->fillForm(['status' => ClaimStatus::Cancelled->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(5, $this->kimaiDeletedIds());
        $this->assertSame(
            5,
            $claim->timesheets()->where('status', LeaveTimesheetStatus::Deleted->value)->count(),
        );
    }

    #[Test]
    public function ct_04_klaim_tanpa_koneksi_kimai_tetap_tersimpan_tanpa_menyentuh_jaringan(): void
    {
        // employee() sengaja tidak punya token: fitur cuti-ke-Kimai tidak boleh
        // mengubah apa pun bagi tim yang tidak memakai Kimai.
        $user = $this->employee();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');
        $this->fakeKimai([]);

        Livewire::test(CreateLeaveClaim::class)
            ->fillForm([
                'claim_type' => ClaimType::FullDay->value,
                'claim_date' => '2026-04-01',
                'status' => ClaimStatus::Submitted->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Http::assertNothingSent();
        $this->assertSame(0, LeaveClaim::query()->sole()->timesheets()->count());
    }

    #[Test]
    public function teks_email_memuat_tiga_hal_wajib_sop(): void
    {
        $user = $this->employee();
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');
        $claim = $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);

        $body = LeaveClaimsTable::emailBody($claim->fresh());

        $this->assertStringContainsString('12 Maret 2026', $body);                       // tanggal overtime
        $this->assertStringContainsString('8 jam', $body);                               // total jam
        $this->assertStringContainsString('https://onedrive.example.test/spl/123', $body); // evidence
        $this->assertStringContainsString('1 April 2026', $body);                        // tanggal cuti
    }

    #[Test]
    public function klaim_hanya_terlihat_oleh_pemiliknya(): void
    {
        $saya = $this->employee();
        $lain = $this->employee();
        $this->logOvertime($lain, '2026-03-12', '09:00', '19:00');
        $klaimOrangLain = $this->submitClaim($lain, '2026-04-01', ClaimType::FullDay);

        $this->actingAs($saya);

        Livewire::test(ListLeaveClaims::class)
            ->assertCanNotSeeTableRecords([$klaimOrangLain]);
    }
}
