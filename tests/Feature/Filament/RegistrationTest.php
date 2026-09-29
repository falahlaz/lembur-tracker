<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Pages\Auth\Register;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Support\Registration;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Registrasi mandiri yang dibuka-tutup admin, plus koneksi Kimai opsional. */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-09-28');
        $this->baselineRule();
    }

    private function admin(): User
    {
        $admin = $this->employee();
        $admin->update(['role' => Role::Admin]);

        return $admin->refresh();
    }

    /** @return array<string, string> */
    private function isian(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Teman Baru',
            'email' => 'teman@lemburku.test',
            'password' => 'password-teman-123',
            'passwordConfirmation' => 'password-teman-123',
        ], $overrides);
    }

    #[Test]
    public function registrasi_tertutup_secara_default(): void
    {
        $this->assertFalse(Registration::isOpen());

        $this->get(Filament::getRegistrationUrl())->assertNotFound();
        $this->get(Filament::getLoginUrl())->assertOk()->assertDontSee(Filament::getRegistrationUrl());
    }

    #[Test]
    public function saat_dibuka_link_daftar_muncul_di_halaman_login(): void
    {
        Registration::open();

        $this->get(Filament::getRegistrationUrl())->assertOk()
            ->assertSee('Hubungkan ke Kimai (opsional)')
            ->assertSee('API Access');
        $this->get(Filament::getLoginUrl())->assertOk()->assertSee(Filament::getRegistrationUrl());
    }

    #[Test]
    public function admin_bisa_membuka_dan_menutup_registrasi(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ListUsers::class)
            ->assertActionHidden('tutupRegistrasi')
            ->callAction('bukaRegistrasi');

        $this->assertTrue(Registration::isOpen());

        Livewire::test(ListUsers::class)
            ->assertSee(Filament::getRegistrationUrl())
            ->assertActionHidden('bukaRegistrasi')
            ->callAction('tutupRegistrasi');

        $this->assertFalse(Registration::isOpen());
    }

    #[Test]
    public function karyawan_tidak_bisa_membuka_halaman_user(): void
    {
        $this->actingAs($this->employee());

        $this->get(ListUsers::getUrl())->assertForbidden();
    }

    #[Test]
    public function daftar_tanpa_kimai_langsung_jadi_karyawan_aktif(): void
    {
        Registration::open();

        Livewire::test(Register::class)
            ->fillForm($this->isian())
            ->call('register')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'teman@lemburku.test')->firstOrFail();

        $this->assertSame(Role::Employee, $user->role);
        $this->assertTrue($user->is_active);
        // Password pilihan sendiri — tidak perlu diganti lagi.
        $this->assertFalse($user->mustChangePassword());
        $this->assertFalse($user->hasKimaiConnection());
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function daftar_dengan_token_kimai_yang_lulus_langsung_terhubung(): void
    {
        Registration::open();
        Http::fake(['*/api/timesheets*' => Http::response([], 200)]);

        Livewire::test(Register::class)
            ->fillForm($this->isian(['kimai_token' => 'token-teman-cd34']))
            ->call('register')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'teman@lemburku.test')->firstOrFail();

        $this->assertTrue($user->hasKimaiConnection());
        $this->assertSame('cd34', $user->kimai_token_last4);
        $this->assertNotNull($user->kimai_token_valid_at);
    }

    #[Test]
    public function token_kimai_yang_ditolak_membatalkan_pendaftaran(): void
    {
        Registration::open();
        Http::fake(['*/api/timesheets*' => Http::response(['message' => 'Unauthorized'], 401)]);

        Livewire::test(Register::class)
            ->fillForm($this->isian(['kimai_token' => 'token-salah']))
            ->call('register')
            ->assertHasFormErrors(['kimai_token']);

        $this->assertFalse(User::query()->where('email', 'teman@lemburku.test')->exists());
        $this->assertGuest();
    }

    #[Test]
    public function tes_koneksi_kimai_tidak_membuat_akun(): void
    {
        Registration::open();
        Http::fake(['*/api/timesheets*' => Http::response([], 200)]);

        Livewire::test(Register::class)
            ->fillForm($this->isian(['kimai_token' => 'token-teman-cd34']))
            ->call('testKimaiToken')
            ->assertNotified('Koneksi Kimai berhasil');

        $this->assertFalse(User::query()->where('email', 'teman@lemburku.test')->exists());
    }

    #[Test]
    public function registrasi_yang_ditutup_saat_form_terbuka_ditolak(): void
    {
        Registration::open();

        $form = Livewire::test(Register::class)->fillForm($this->isian());

        Registration::close();

        $form->call('register')->assertNotified('Registrasi sudah ditutup');

        $this->assertFalse(User::query()->where('email', 'teman@lemburku.test')->exists());
        $this->assertGuest();
    }
}
