<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Pages\Preferensi;
use App\Filament\Resources\OvertimeRules\OvertimeRuleResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** F-01 / F-09 — kelola user, versi aturan, dan preferensi. */
class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
    }

    private function admin(): User
    {
        $admin = $this->employee();
        $admin->update(['role' => Role::Admin]);

        return $admin->refresh();
    }

    #[Test]
    public function karyawan_biasa_tidak_bisa_membuka_halaman_admin(): void
    {
        $this->actingAs($this->employee());

        $this->assertFalse(UserResource::canAccess());
        $this->assertFalse(OvertimeRuleResource::canAccess());

        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->get(OvertimeRuleResource::getUrl('index'))->assertForbidden();
    }

    #[Test]
    public function admin_bisa_membuka_dan_membuat_user(): void
    {
        $this->actingAs($this->admin());

        $this->get(UserResource::getUrl('index'))->assertOk();

        Livewire::test(\App\Filament\Resources\Users\Pages\CreateUser::class)
            ->fillForm([
                'name' => 'Karyawan Baru',
                'email' => 'baru@lemburku.test',
                'role' => Role::Employee->value,
                'password' => 'rahasia-sekali',
                'is_active' => true,
                'default_late_arrival_time' => '13:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $baru = User::query()->where('email', 'baru@lemburku.test')->sole();
        $this->assertTrue($baru->is_active);
        // BR-04 — default pembulatan MATI.
        $this->assertFalse($baru->rounding_enabled);
        // Password ter-hash, bukan tersimpan apa adanya.
        $this->assertNotSame('rahasia-sekali', $baru->password);
    }

    #[Test]
    public function admin_bisa_membuat_versi_aturan_baru(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(\App\Filament\Resources\OvertimeRules\Pages\CreateOvertimeRule::class)
            ->fillForm([
                'effective_from' => '2026-06-01',
                'tier1_min_minutes' => 240,
                'tier1_meal_amount' => 75000,
                'tier1_leave_minutes' => 240,
                'tier2_min_minutes' => 480,
                'tier2_meal_amount' => 150000,
                'tier2_leave_minutes' => 480,
                'cutoff_day' => 19,
                'expiry_months' => 1,
                'monthly_claim_quota_days' => 3.0,
                'work_start_time' => '09:00',
                'work_end_time' => '18:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, \App\Models\OvertimeRule::query()->count());
    }

    #[Test]
    public function preferensi_menyimpan_pembulatan_dan_notifikasi(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        Livewire::test(Preferensi::class)
            ->assertSee('lebih longgar daripada bunyi SOP')
            ->fillForm([
                'rounding_enabled' => true,
                'default_late_arrival_time' => '14:00',
                'notif_expiry_h7' => true,
                'notif_expiry_h2' => false,
                'notif_cutoff' => true,
                'notif_summary' => false,
                'notif_review' => true,
            ])
            ->call('save');

        $user->refresh();
        $this->assertTrue($user->rounding_enabled);
        $this->assertTrue($user->wantsNotification(NotificationLog::EXPIRY_H7));
        $this->assertFalse($user->wantsNotification(NotificationLog::EXPIRY_H2));
        $this->assertFalse($user->wantsNotification(NotificationLog::PERIOD_SUMMARY));
    }

    #[Test]
    public function admin_bisa_mencatat_lembur_atas_nama_user_lain(): void
    {
        // OQ-2 — backfill data sebelum sistem dipakai.
        $admin = $this->admin();
        $karyawan = $this->employee();
        $this->actingAs($admin);

        Livewire::test(\App\Filament\Resources\OvertimeRecords\Pages\CreateOvertimeRecord::class)
            ->fillForm([
                'user_id' => $karyawan->id,
                'overtime_date' => '2026-03-19',
                'start_time' => '19:00',
                'end_time' => '23:30',
                'work_description' => 'Backfill lembur sebelum sistem live',
                'evidence_url' => 'https://onedrive.example.test/spl/9',
                'status' => 'recorded',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $record = \App\Models\OvertimeRecord::query()->sole();
        $this->assertSame($karyawan->id, $record->user_id);
        // Jejaknya jelas: dicatat admin, bukan oleh karyawannya sendiri.
        $this->assertSame($admin->id, $record->created_by_id);
        $this->assertTrue($record->wasCreatedOnBehalf());
    }
}
