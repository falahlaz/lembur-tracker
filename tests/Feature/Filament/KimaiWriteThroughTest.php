<?php

namespace Tests\Feature\Filament;

use App\Domain\Kimai\KimaiSynchronizer;
use App\Filament\Resources\OvertimeRecords\Pages\EditOvertimeRecord;
use App\Filament\Resources\OvertimeRecords\Pages\ListOvertimeRecords;
use App\Models\OvertimeRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Hapus dan ubah lembur ikut diteruskan ke Kimai, dan gagal di Kimai = tidak ada yang berubah. */
class KimaiWriteThroughTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-09-13');
        $this->baselineRule();
    }

    /** Satu lembur Kimai dari satu entri 18:00–20:00 pada Minggu 2026-09-06. */
    private function lemburSatuEntri(): OvertimeRecord
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiSlot(501, '2026-09-06', '18:00', '20:00')]);
        app(KimaiSynchronizer::class)->run($user);
        $this->actingAs($user);

        return OvertimeRecord::sole();
    }

    #[Test]
    public function hapus_lembur_ikut_menghapus_entri_di_kimai(): void
    {
        $record = $this->lemburSatuEntri();

        Livewire::test(ListOvertimeRecords::class)->callTableAction('delete', $record);

        $this->assertSame([501], $this->kimaiDeletedIds());
        $this->assertSoftDeleted($record);
    }

    #[Test]
    public function hapus_dibatalkan_kalau_kimai_gagal(): void
    {
        $record = $this->lemburSatuEntri();
        $this->failKimaiDeleteAt(1, 503);

        Livewire::test(ListOvertimeRecords::class)->callTableAction('delete', $record);

        $this->assertNotSoftDeleted($record);
    }

    #[Test]
    public function lembur_manual_dihapus_tanpa_menyentuh_kimai(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([]);
        $this->actingAs($user);
        $record = $this->logOvertime($user, '2026-09-06', '18:00', '20:00');

        Livewire::test(ListOvertimeRecords::class)->callTableAction('delete', $record);

        $this->assertSame([], $this->kimaiDeletedIds());
        $this->assertSoftDeleted($record);
    }

    #[Test]
    public function ubah_deskripsi_dan_jam_diteruskan_ke_kimai(): void
    {
        $record = $this->lemburSatuEntri();

        Livewire::test(EditOvertimeRecord::class, ['record' => $record->getKey()])
            ->fillForm(['work_description' => 'Deskripsi yang sudah dibetulkan', 'end_time' => '21:00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $patch = $this->kimaiPatched();
        $this->assertCount(1, $patch);
        $this->assertSame(501, $patch[0]['id']);
        $this->assertSame('Deskripsi yang sudah dibetulkan', $patch[0]['body']['description']);
        $this->assertSame('2026-09-06T21:00:00+0700', $patch[0]['body']['end']);
        $this->assertSame('Deskripsi yang sudah dibetulkan', $record->refresh()->work_description);
        $this->assertSame('Deskripsi yang sudah dibetulkan', $record->kimaiEntries()->sole()->description);
    }

    #[Test]
    public function ubah_status_saja_tidak_memanggil_kimai(): void
    {
        $record = $this->lemburSatuEntri();

        Livewire::test(EditOvertimeRecord::class, ['record' => $record->getKey()])
            ->fillForm(['notes' => 'cuma catatan'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([], $this->kimaiPatched());
        $this->assertSame('cuma catatan', $record->refresh()->notes);
    }

    #[Test]
    public function ubah_tidak_tersimpan_kalau_kimai_menolak(): void
    {
        $record = $this->lemburSatuEntri();
        $this->kimaiPatchStatus = 400;

        Livewire::test(EditOvertimeRecord::class, ['record' => $record->getKey()])
            ->fillForm(['work_description' => 'Deskripsi yang tidak jadi disimpan'])
            ->call('save');

        $this->assertNotSame('Deskripsi yang tidak jadi disimpan', $record->refresh()->work_description);
    }

    #[Test]
    public function jam_lembur_gabungan_tidak_bisa_diubah(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([
            $this->kimaiSlot(1, '2026-09-06', '18:00', '20:00'),
            $this->kimaiSlot(2, '2026-09-06', '20:00', '22:00'),
        ]);
        app(KimaiSynchronizer::class)->run($user);
        $this->actingAs($user);
        $record = OvertimeRecord::sole();

        Livewire::test(EditOvertimeRecord::class, ['record' => $record->getKey()])
            ->fillForm(['end_time' => '23:00'])
            ->call('save');

        $this->assertSame([], $this->kimaiPatched());
        $this->assertSame('22:00', substr((string) $record->refresh()->end_time, 0, 5));
    }
}
