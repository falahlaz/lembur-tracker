<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OvertimeRecords\Pages\CreateOvertimeRecord;
use App\Filament\Resources\OvertimeRecords\Pages\ListOvertimeRecords;
use App\Models\LeaveBalance;
use App\Models\OvertimeRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** F-02 / F-05 — form catat lembur dan history, lewat komponen Filament sungguhan. */
class OvertimeRecordResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
    }

    #[Test]
    public function form_menyimpan_lembur_dan_menghitung_haknya(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        Livewire::test(CreateOvertimeRecord::class)
            ->fillForm([
                'overtime_date' => '2026-03-19',
                'start_time' => '19:00',
                'end_time' => '23:30',
                'work_description' => 'Hotfix payment gateway timeout',
                'evidence_url' => 'https://onedrive.example.test/spl/1',
                'status' => 'recorded',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $record = OvertimeRecord::query()->sole();
        $this->assertSame($user->id, $record->user_id);
        $this->assertSame(270, $record->duration_effective_minutes);
        $this->assertSame(50_000, $record->meal_allowance_amount);
        $this->assertSame($user->id, $record->created_by_id);
        $this->assertSame('2026-04-19', $record->leaveBalance->expires_at->toDateString());
    }

    #[Test]
    public function form_menolak_sesi_yang_tumpang_tindih_di_tanggal_yang_sama(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-19', '19:00', '23:00');

        Livewire::test(CreateOvertimeRecord::class)
            ->fillForm([
                'overtime_date' => '2026-03-19',
                'start_time' => '20:00',
                'end_time' => '22:00',
                'work_description' => 'Sesi yang bertabrakan',
                'evidence_url' => 'https://onedrive.example.test/spl/2',
                'status' => 'recorded',
            ])
            ->call('create')
            ->assertHasFormErrors(['end_time']);

        $this->assertSame(1, OvertimeRecord::query()->count());
    }

    #[Test]
    public function form_menerima_sesi_kedua_yang_tidak_bertabrakan(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-19', '18:00', '21:00');

        Livewire::test(CreateOvertimeRecord::class)
            ->fillForm([
                'overtime_date' => '2026-03-19',
                'start_time' => '07:00',
                'end_time' => '09:00',
                'work_description' => 'Sesi pagi sebelum jam kerja',
                'evidence_url' => 'https://onedrive.example.test/spl/3',
                'status' => 'recorded',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // BR-02 — total harian 5 jam → tier 1, dan hanya SATU batch (BR-07).
        $this->assertSame(2, OvertimeRecord::query()->count());
        $this->assertSame(1, LeaveBalance::query()->count());
        $this->assertSame(240, LeaveBalance::query()->sole()->earned_minutes);
    }

    #[Test]
    public function form_menolak_tanggal_masa_depan(): void
    {
        $this->actingAs($this->employee());

        Livewire::test(CreateOvertimeRecord::class)
            ->fillForm([
                'overtime_date' => '2026-04-01',
                'start_time' => '19:00',
                'end_time' => '23:30',
                'work_description' => 'Lembur yang belum terjadi',
                'evidence_url' => 'https://onedrive.example.test/spl/4',
                'status' => 'recorded',
            ])
            ->call('create')
            ->assertHasFormErrors(['overtime_date']);
    }

    #[Test]
    public function form_menolak_deskripsi_pendek_dan_url_tidak_valid(): void
    {
        $this->actingAs($this->employee());

        Livewire::test(CreateOvertimeRecord::class)
            ->fillForm([
                'overtime_date' => '2026-03-19',
                'start_time' => '19:00',
                'end_time' => '23:30',
                'work_description' => 'pendek',
                'evidence_url' => 'bukan-url',
                'status' => 'recorded',
            ])
            ->call('create')
            ->assertHasFormErrors(['work_description', 'evidence_url']);
    }

    #[Test]
    public function history_hanya_menampilkan_lembur_milik_sendiri(): void
    {
        $saya = $this->employee();
        $orangLain = $this->employee();

        $milikSaya = $this->logOvertime($saya, '2026-03-10', '19:00', '23:30');
        $milikDia = $this->logOvertime($orangLain, '2026-03-11', '19:00', '23:30');

        $this->actingAs($saya);

        Livewire::test(ListOvertimeRecords::class)
            ->assertCanSeeTableRecords([$milikSaya])
            ->assertCanNotSeeTableRecords([$milikDia]);
    }

    #[Test]
    public function preview_hak_tampil_live_sebelum_disimpan(): void
    {
        // P-3 — perhitungan dilakukan di depan mata, bukan setelah simpan.
        $this->actingAs($this->employee());

        Livewire::test(CreateOvertimeRecord::class)
            ->fillForm([
                'overtime_date' => '2026-03-19',
                'start_time' => '19:00',
                'end_time' => '23:30',
            ])
            ->assertSee('4 jam 30 menit')
            ->assertSee('Rp50.000')
            ->assertSee('19 April 2026')          // berlaku s/d
            ->assertSee('periode April 2026')
            ->assertSee('datang siang 4 jam')     // P-2 terjemahan manusiawi
            ->assertSee('bukan perhitungan payroll resmi');   // P-5 disclaimer

        $this->assertSame(0, OvertimeRecord::query()->count());
    }

    #[Test]
    public function preview_di_bawah_empat_jam_bernada_netral_bukan_error(): void
    {
        $this->actingAs($this->employee());

        Livewire::test(CreateOvertimeRecord::class)
            ->fillForm([
                'overtime_date' => '2026-03-19',
                'start_time' => '19:00',
                'end_time' => '22:20',            // 3j20m
            ])
            ->assertSee('belum mencapai minimal 4 jam')
            ->assertSee('Catatannya tetap bisa disimpan')
            ->assertDontSee('Rp50.000');
    }

    #[Test]
    public function preview_menampilkan_pembulatan_secara_eksplisit(): void
    {
        // BR-04 — pembulatan tidak boleh terjadi diam-diam.
        $this->actingAs($this->employee(rounding: true));

        Livewire::test(CreateOvertimeRecord::class)
            ->fillForm([
                'overtime_date' => '2026-03-19',
                'start_time' => '19:00',
                'end_time' => '22:40',            // 3j40m → dibulatkan jadi 4 jam
            ])
            ->assertSee('3 jam 40 menit dibulatkan jadi 4 jam')
            ->assertSee('Rp50.000');
    }

    #[Test]
    public function preview_memperingatkan_record_yang_lewat_cut_off(): void
    {
        // BR-11 — memperingatkan, tidak memblokir.
        $this->actingAs($this->employee());

        Livewire::test(CreateOvertimeRecord::class)
            ->fillForm([
                'overtime_date' => '2026-02-10',   // periodenya ditutup 18 Feb
                'start_time' => '19:00',
                'end_time' => '23:30',
            ])
            ->assertSee('Berisiko melewati cut-off');
    }
    #[Test]
    public function audit_trail_mencatat_siapa_kapan_dan_apa_yang_berubah(): void
    {
        // F-10 — setiap perubahan tercatat: siapa, kapan, field apa, lama → baru.
        $user = $this->employee();
        $this->actingAs($user);

        $record = $this->logOvertime($user, '2026-03-19', '19:00', '23:30');
        $record->update(['status' => \App\Enums\OvertimeStatus::Approved]);

        $trail = \App\Filament\Resources\OvertimeRecords\Schemas\OvertimeRecordInfolist::auditTrail($record->fresh());

        $this->assertNotEmpty($trail);
        $this->assertSame($user->name, $trail[0]['who']);
        // Nama kolom dan nilai enum tampil dalam bahasa UI, bukan bahasa database.
        $this->assertStringContainsString('Status', $trail[0]['what']);
        $this->assertStringContainsString('Disetujui', $trail[0]['what']);
    }

    #[Test]
    public function halaman_detail_menampilkan_perhitungan_dan_riwayat(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $record = $this->logOvertime($user, '2026-03-19', '19:00', '23:30');

        $this->get(\App\Filament\Resources\OvertimeRecords\OvertimeRecordResource::getUrl('view', ['record' => $record]))
            ->assertOk()
            ->assertSee('4 jam 30 menit')
            ->assertSee('Rp50.000')
            ->assertSee('Riwayat perubahan')
            // Timeline benar-benar terender, bukan sekadar judul section-nya.
            ->assertSee('Dicatat oleh')
            ->assertSee('Lihat detail')
            ->assertSee('bukan perhitungan payroll resmi');
    }
}
