<?php

namespace Tests\Feature\Filament;

use App\Enums\ClaimType;
use App\Filament\Pages\CutiPengganti;
use App\Filament\Resources\LeaveClaims\LeaveClaimResource;
use App\Filament\Resources\OvertimeRecords\OvertimeRecordResource;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Merender halaman sungguhan lewat HTTP. Test Livewire per komponen tidak
 * menangkap kerusakan yang muncul saat layout, navigasi dan seluruh widget
 * dirakit jadi satu halaman.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
    }

    #[Test]
    public function halaman_utama_terbuka_untuk_user_yang_login(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        $this->logOvertime($user, '2026-02-25', '19:00', '23:30');   // memicu banner
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');
        $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);

        $this->get('/app')->assertOk()->assertSee('Halo, '.$user->name);
        $this->get(OvertimeRecordResource::getUrl('index'))->assertOk();
        $this->get(OvertimeRecordResource::getUrl('create'))->assertOk()->assertSee('Catat Lembur');
        $this->get(CutiPengganti::getUrl())->assertOk()->assertSee('Saldo aktif');
        $this->get(LeaveClaimResource::getUrl('create'))->assertOk()->assertSee('Ajukan Klaim');
    }

    #[Test]
    public function tamu_diarahkan_ke_login(): void
    {
        $this->get('/app')->assertRedirect('/app/login');
        $this->get('/app/login')->assertOk()->assertSee('Masuk');
    }

    #[Test]
    public function user_nonaktif_tidak_bisa_masuk_panel(): void
    {
        // F-01 — datanya tetap tersimpan, tetapi aksesnya dicabut.
        $user = $this->employee();
        $user->update(['is_active' => false]);

        $this->actingAs($user)->get('/app')->assertForbidden();
    }

    /**
     * Kredensial yang ditulis di README dan dipakai saat demo harus benar-benar
     * bisa masuk. Seeder menyimpan password sebagai teks biasa dan bergantung
     * sepenuhnya pada cast `password => hashed` di model; kalau cast itu hilang,
     * hash tidak pernah terbentuk dan seluruh akun demo terkunci tanpa satu pun
     * error yang kelihatan.
     */
    #[Test]
    public function kredensial_seeder_bisa_masuk(): void
    {
        $this->seed(DemoUserSeeder::class);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'falah@lemburku.test',
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs(
            User::query()->where('email', 'falah@lemburku.test')->sole(),
        );
    }

    #[Test]
    public function password_salah_tidak_bisa_masuk(): void
    {
        $this->seed(DemoUserSeeder::class);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'falah@lemburku.test',
                'password' => 'bukan-password-yang-benar',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    #[Test]
    public function user_nonaktif_ditolak_di_halaman_login(): void
    {
        // F-01 — dicek di pintu masuk, bukan hanya saat membuka /app.
        $this->seed(DemoUserSeeder::class);
        User::query()->where('email', 'falah@lemburku.test')->update(['is_active' => false]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'falah@lemburku.test',
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    #[Test]
    public function navigasi_memakai_bahasa_indonesia(): void
    {
        $this->actingAs($this->employee());

        $this->get('/app')
            ->assertSee('Beranda')
            ->assertSee('Pencatatan')
            ->assertSee('Lembur')
            ->assertSee('Cuti Pengganti');
    }
}
