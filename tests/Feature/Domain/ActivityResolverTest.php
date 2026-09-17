<?php

namespace Tests\Feature\Domain;

use App\Domain\Timesheet\ActivityResolver;
use App\Domain\Timesheet\ParsedEntry;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Menerjemahkan nama activity di sheet menjadi id Kimai. */
class ActivityResolverTest extends TestCase
{
    private ActivityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new ActivityResolver;
    }

    private function entry(?string $nama, ?int $id = null): ParsedEntry
    {
        $tanggal = CarbonImmutable::parse('2026-08-18', config('kimai.timezone'));

        return new ParsedEntry(
            sheet: 'Daily',
            cellRef: 'Daily!B5',
            slotLabel: '9 AM - 10 AM',
            workDate: $tanggal,
            beginAt: $tanggal->setTime(9, 0),
            endAt: $tanggal->setTime(10, 0),
            activityId: $id,
            activityName: $nama,
            description: 'Daily Meeting',
            tag: null,
        );
    }

    /** @return array<int, array{id: int, name: string, project: ?int}> */
    private function daftar(): array
    {
        return [
            ['id' => 8, 'name' => '31_DEV_FEATURE', 'project' => 105],
            ['id' => 25, 'name' => '12_PROJECT_MEETING', 'project' => null],
        ];
    }

    #[Test]
    public function nama_yang_persis_sama_diresolusi(): void
    {
        $entry = $this->entry('31_DEV_FEATURE');

        $this->assertSame(0, $this->resolver->resolve([$entry], $this->daftar(), 'MyTelkomsel'));
        $this->assertSame(8, $entry->activityId);
        $this->assertTrue($entry->isPostable());
    }

    #[Test]
    public function activity_global_ikut_terjangkau(): void
    {
        // Activity global (`project` null) berlaku di semua project; ia diambil
        // lewat permintaan terpisah dan digabung, jadi harus ikut ketemu di sini.
        $entry = $this->entry('12_PROJECT_MEETING');

        $this->resolver->resolve([$entry], $this->daftar(), 'MyTelkomsel');

        $this->assertSame(25, $entry->activityId);
    }

    #[Test]
    public function besar_kecil_huruf_dan_pemisah_kata_dimaafkan(): void
    {
        // Perbedaan yang lahir dari menyalin nama dengan tangan, bukan perbedaan
        // activity.
        foreach (['31_dev_feature', '31 DEV FEATURE', '31-Dev-Feature', '  31_DEV_FEATURE  '] as $varian) {
            $entry = $this->entry($varian);
            $this->resolver->resolve([$entry], $this->daftar(), 'MyTelkomsel');

            $this->assertSame(8, $entry->activityId, "'{$varian}' seharusnya cocok.");
        }
    }

    #[Test]
    public function nama_tak_dikenal_ditandai_dengan_alasan_yang_menyebut_namanya(): void
    {
        $entry = $this->entry('31_DEV_FEATUR');

        $this->assertSame(1, $this->resolver->resolve([$entry], $this->daftar(), 'C5385 - MyTelkomsel'));
        $this->assertNull($entry->activityId);
        $this->assertFalse($entry->isPostable());
        $this->assertStringContainsString('31_DEV_FEATUR', $entry->skipReason);
        $this->assertStringContainsString('C5385 - MyTelkomsel', $entry->skipReason);
    }

    #[Test]
    public function nama_yang_cocok_ke_lebih_dari_satu_ditolak_bukan_ditebak(): void
    {
        // Menebak salah satu berarti jam kerja masuk ke activity yang keliru tanpa
        // ada yang tahu.
        $daftar = [
            ['id' => 8, 'name' => 'Deploy', 'project' => 105],
            ['id' => 9, 'name' => 'deploy', 'project' => null],
        ];

        $entry = $this->entry('DEPLOY');

        $this->assertSame(1, $this->resolver->resolve([$entry], $daftar, 'MyTelkomsel'));
        $this->assertNull($entry->activityId);
        $this->assertStringContainsString('lebih dari satu', $entry->skipReason);
        $this->assertStringContainsString('8', $entry->skipReason);
        $this->assertStringContainsString('9', $entry->skipReason);
    }

    #[Test]
    public function kecocokan_persis_menang_atas_kecocokan_longgar(): void
    {
        // Dua activity yang hanya beda besar-kecil huruf: yang persis sama harus
        // menang, bukan dilaporkan ambigu.
        $daftar = [
            ['id' => 8, 'name' => 'Deploy', 'project' => 105],
            ['id' => 9, 'name' => 'DEPLOY', 'project' => 105],
        ];

        $entry = $this->entry('Deploy');

        $this->assertSame(0, $this->resolver->resolve([$entry], $daftar, 'MyTelkomsel'));
        $this->assertSame(8, $entry->activityId);
    }

    #[Test]
    public function entri_yang_sudah_punya_id_tidak_disentuh(): void
    {
        // Sel format lama sudah membawa id-nya sendiri.
        $entry = $this->entry(null, id: 17);

        $this->assertSame(0, $this->resolver->resolve([$entry], $this->daftar(), 'MyTelkomsel'));
        $this->assertSame(17, $entry->activityId);
    }
}
