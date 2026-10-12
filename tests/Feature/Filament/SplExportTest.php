<?php

namespace Tests\Feature\Filament;

use App\Enums\OvertimeStatus;
use App\Exports\SplExport;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Rekap;
use App\Models\KimaiProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** SPL — template kantor terisi dari catatan lembur. */
class SplExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
        Storage::fake('local');
    }

    private function splUser(): User
    {
        $user = $this->employee();
        $user->update([
            'name' => 'Al Falah Lazuardi',
            'division' => 'IT Developer',
            'position' => 'Mobile Developer',
            'default_project' => 'MyTelkomsel - Native',
            'approver1_name' => 'Budi',
            'approver1_position' => 'Project Manager',
            'approver2_name' => 'Sari',
            'approver2_position' => 'Head of Delivery',
        ]);

        return $user->refresh();
    }

    private function sheet(User $user, string $from = '2026-02-19', string $to = '2026-03-18'): Worksheet
    {
        $export = new SplExport($user, Date::parse($from), Date::parse($to));
        $path = $export->store();

        try {
            // Dibaca ulang dari berkas sungguhan: membuktikan hasil tulisnya valid.
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function kepala_baris_dan_kaki_terisi_dari_data(): void
    {
        $user = $this->splUser();

        KimaiProject::query()->create([
            'kimai_id' => 105, 'name' => 'C5385 - MyTelkomsel', 'customer' => 'Telkomsel', 'synced_at' => now(),
        ]);

        $kimai = $this->logOvertime($user, '2026-03-10', '21:00', '01:00');
        $kimai->forceFill(['kimai_project_id' => 105, 'evidence_url' => 'https://share.example.test/Evidence/OT_Evidence_10%20Mar%202026.png?e=1'])->save();
        $this->logOvertime($user, '2026-02-28', '09:00', '16:30', OvertimeStatus::Approved);
        $this->logOvertime($user, '2026-03-11', '22:00', '23:00', OvertimeStatus::Rejected);
        $this->logOvertime($user, '2026-03-19', '19:00', '23:00');   // di luar periode

        $sheet = $this->sheet($user);

        $this->assertSame(':  Al Falah Lazuardi', $sheet->getCell('D6')->getValue());
        $this->assertSame(':  19/02/2026 - 18/03/2026', $sheet->getCell('D7')->getValue());
        $this->assertSame(':  IT Developer', $sheet->getCell('D8')->getValue());

        // Urut tanggal; record manual memakai project default.
        $this->assertSame('MyTelkomsel - Native', $sheet->getCell('C12')->getValue());
        $this->assertSame('2026-02-28', ExcelDate::excelToDateTimeObject($sheet->getCell('E12')->getValue())->format('Y-m-d'));
        $this->assertEqualsWithDelta(9 / 24, $sheet->getCell('G12')->getValue(), 0.0001);
        $this->assertEqualsWithDelta(16.5 / 24, $sheet->getCell('J12')->getValue(), 0.0001);
        $this->assertEquals(7.5, $sheet->getCell('K12')->getValue());

        // Record Kimai memakai nama project Kimai, dan lembur lewat tengah malam tetap 4 jam.
        $this->assertSame('C5385 - MyTelkomsel', $sheet->getCell('C13')->getValue());
        $this->assertEqualsWithDelta(21 / 24, $sheet->getCell('I13')->getValue(), 0.0001);
        $this->assertEqualsWithDelta(1 / 24, $sheet->getCell('J13')->getValue(), 0.0001);
        $this->assertEquals(4, $sheet->getCell('K13')->getValue());
        $this->assertSame('OT_Evidence_10 Mar 2026.png', $sheet->getCell('L13')->getValue());
        $this->assertStringStartsWith('https://share.example.test/', $sheet->getCell('L13')->getHyperlink()->getUrl());

        // Yang ditolak dan yang di luar periode tidak ikut.
        $this->assertNull($sheet->getCell('C14')->getValue());
        $this->assertSame('=SUM(K12:K36)', $sheet->getCell('K37')->getValue());

        $this->assertSame('(Al Falah Lazuardi)', $sheet->getCell('C45')->getValue());
        $this->assertSame('Mobile Developer', $sheet->getCell('C46')->getValue());
        $this->assertSame('(Budi)', $sheet->getCell('D45')->getValue());
        $this->assertSame('Project Manager', $sheet->getCell('D46')->getValue());
        $this->assertSame('(Sari)', $sheet->getCell('E45')->getValue());
        $this->assertSame('Head of Delivery', $sheet->getCell('E46')->getValue());
    }

    #[Test]
    public function tanda_tangan_ditempel_bila_sudah_diunggah(): void
    {
        $user = $this->splUser();
        $this->logOvertime($user, '2026-03-02', '19:00', '23:00');

        // Hanya logo kantor bawaan template.
        $this->assertCount(1, $this->sheet($user)->getDrawingCollection());

        $path = UploadedFile::fake()->image('ttd.png', 200, 180)->store('signatures', 'local');
        $user->update(['signature_path' => $path]);

        $drawings = $this->sheet($user)->getDrawingCollection();
        $this->assertCount(2, $drawings);
        $this->assertContains('C41', array_map(fn ($d) => $d->getCoordinates(), iterator_to_array($drawings)));
    }

    #[Test]
    public function lebih_dari_dua_puluh_lima_lembur_menambah_baris(): void
    {
        $user = $this->splUser();

        foreach (range(0, 27) as $i) {
            $this->logOvertime($user, Date::parse('2026-02-19')->addDays($i)->toDateString(), '19:00', '21:00');
        }

        $sheet = $this->sheet($user);

        $this->assertSame(28, $sheet->getCell('B39')->getValue());
        $this->assertSame('Total', $sheet->getCell('B40')->getValue());
        $this->assertSame('=SUM(K12:K39)', $sheet->getCell('K40')->getValue());
        $this->assertSame('(Al Falah Lazuardi)', $sheet->getCell('C48')->getValue());
    }

    #[Test]
    public function nama_berkas_meniru_template_kantor(): void
    {
        $export = new SplExport($this->splUser(), Date::parse('2026-02-19'), Date::parse('2026-03-18'));

        $this->assertSame('CODE.ID - SPL AL FALAH LAZUARDI - 202603.xlsx', $export->fileName());
    }

    #[Test]
    public function tombol_unduh_spl_di_halaman_rekap(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-19', '19:00', '23:00');

        Livewire::test(Rekap::class)
            ->assertSee('Unduh SPL')
            ->assertSee('Belum diisi: Divisi')
            ->call('exportSpl')
            ->assertFileDownloaded('CODE.ID - SPL KARYAWAN UJI - 202604.xlsx');
    }

    #[Test]
    public function data_spl_dan_tanda_tangan_diisi_di_profil(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'division' => 'IT Developer',
                'position' => 'Mobile Developer',
                'approver1_name' => 'Budi',
                'approver2_name' => 'Sari',
                'signature_path' => UploadedFile::fake()->image('ttd.png', 200, 180),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertSame('IT Developer', $user->division);
        $this->assertStringStartsWith('signatures/', $user->signature_path);
        Storage::disk('local')->assertExists($user->signature_path);
        $this->assertSame([], $user->missingSplFields());

        // Tanda tangan baru menggantikan, yang lama tidak menumpuk di disk.
        $lama = $user->signature_path;

        // Seperti di browser: yang lama dibuang dulu dari field, lalu yang baru diunggah.
        Livewire::test(EditProfile::class)
            ->fillForm(['signature_path' => []])
            ->fillForm(['signature_path' => UploadedFile::fake()->image('ttd-baru.png', 200, 180)])
            ->call('save')
            ->assertHasNoFormErrors();

        Storage::disk('local')->assertMissing($lama);
        Storage::disk('local')->assertExists($user->refresh()->signature_path);
    }
}
