<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\GantiPasswordAwal;
use App\Filament\Pages\Preferensi;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Profil sendiri, ganti password, dan password sementara dari admin. */
class ProfilePasswordTest extends TestCase
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

    private function passwordSementara(): User
    {
        $user = $this->employee();
        $user->update(['must_change_password' => true]);

        return $user->refresh();
    }

    #[Test]
    public function user_bisa_mengubah_nama_tapi_tidak_email(): void
    {
        $user = $this->employee();
        $emailAsli = $user->email;
        $this->actingAs($user);

        $this->get(Filament::getProfileUrl())->assertOk()->assertSee('Profil Saya');

        Livewire::test(EditProfile::class)
            ->fillForm(['name' => 'Nama Baru', 'email' => 'lain@lemburku.test'])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertSame('Nama Baru', $user->name);
        $this->assertSame($emailAsli, $user->email);
    }

    #[Test]
    public function ganti_password_lewat_profil_butuh_password_lama(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'password-baru-123',
                'passwordConfirmation' => 'password-baru-123',
                'currentPassword' => 'salah-total',
            ])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertTrue(Hash::check('password', $user->refresh()->password));

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'password-baru-123',
                'passwordConfirmation' => 'password-baru-123',
                'currentPassword' => 'password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('password-baru-123', $user->refresh()->password));
    }

    #[Test]
    public function user_baru_dari_admin_wajib_ganti_password(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateUser::class)
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

        $this->assertTrue(User::query()->where('email', 'baru@lemburku.test')->sole()->must_change_password);
    }

    #[Test]
    public function reset_password_oleh_admin_menyalakan_lagi_penanda(): void
    {
        $admin = $this->admin();
        $user = $this->employee();
        $this->actingAs($admin);

        // Edit tanpa password tidak menyentuh penanda.
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['name' => 'Ganti Nama'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse($user->refresh()->must_change_password);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['password' => 'reset-dari-admin'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($user->refresh()->must_change_password);

        // Admin mengganti password dirinya sendiri tidak ikut dipaksa.
        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->fillForm(['password' => 'password-admin-baru'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse($admin->refresh()->must_change_password);
    }

    #[Test]
    public function password_sementara_menahan_user_di_halaman_ganti_password(): void
    {
        $this->actingAs($this->passwordSementara());

        $this->get(Filament::getUrl())->assertRedirect(GantiPasswordAwal::getUrl());
        $this->get(Preferensi::getUrl())->assertRedirect(GantiPasswordAwal::getUrl());
        $this->get(Filament::getProfileUrl())->assertRedirect(GantiPasswordAwal::getUrl());

        $this->get(GantiPasswordAwal::getUrl())->assertOk()->assertSee('Buat password baru');
    }

    #[Test]
    public function setelah_ganti_password_user_bisa_masuk_dashboard(): void
    {
        $user = $this->passwordSementara();
        $this->actingAs($user);

        // Tidak boleh sama dengan password sementara dari admin.
        Livewire::test(GantiPasswordAwal::class)
            ->fillForm(['password' => 'password', 'passwordConfirmation' => 'password'])
            ->call('save')
            ->assertHasFormErrors(['password']);

        Livewire::test(GantiPasswordAwal::class)
            ->fillForm(['password' => 'milikku-sendiri', 'passwordConfirmation' => 'beda'])
            ->call('save')
            ->assertHasFormErrors(['password']);

        Livewire::test(GantiPasswordAwal::class)
            ->fillForm(['password' => 'milikku-sendiri', 'passwordConfirmation' => 'milikku-sendiri'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(Filament::getUrl());

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('milikku-sendiri', $user->password));

        $this->get(Filament::getUrl())->assertOk();
    }

    #[Test]
    public function halaman_ganti_password_mengalihkan_user_tanpa_password_sementara(): void
    {
        $this->actingAs($this->employee());

        Livewire::test(GantiPasswordAwal::class)->assertRedirect(Filament::getUrl());
    }

    #[Test]
    public function user_dengan_password_sementara_tetap_bisa_keluar(): void
    {
        $this->actingAs($this->passwordSementara());

        Livewire::test(GantiPasswordAwal::class)
            ->call('logout')
            ->assertRedirect(Filament::getLoginUrl());

        $this->assertGuest();
    }
}
