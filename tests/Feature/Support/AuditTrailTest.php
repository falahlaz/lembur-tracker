<?php

namespace Tests\Feature\Support;

use App\Enums\OvertimeStatus;
use App\Models\OvertimeRecord;
use App\Support\AuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** F-10 — riwayat perubahan harus terbaca tanpa perlu tahu nama kolom database. */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
    }

    #[Test]
    public function nama_kolom_dan_nilainya_diterjemahkan_ke_bahasa_indonesia(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        $record = $this->logOvertime($user, '2026-03-19', '19:00', '23:30');
        $changes = $this->changesByLabel($record->fresh());

        // Bukan 'duration_effective_minutes: 270'.
        $this->assertSame('4 jam 30 menit', $changes['Durasi efektif']['to']);
        // Rp0 dan "Belum 4 jam" memang nilai saat baris ditulis: agregasi per-tanggal
        // dihitung setelahnya lewat saveQuietly() sehingga tidak masuk activity log.
        // Yang diuji di sini formatnya — "Rp0", bukan "0"; "Belum 4 jam", bukan "0".
        $this->assertSame('Rp0', $changes['Uang makan']['to']);
        $this->assertSame('19 Maret 2026', $changes['Tanggal']['to']);
        $this->assertSame('19:00', $changes['Jam mulai']['to']);
        $this->assertSame('23:30', $changes['Jam selesai']['to']);
        $this->assertSame('Dicatat', $changes['Status']['to']);
        $this->assertSame('Belum 4 jam', $changes['Tier']['to']);
        $this->assertSame('tidak', $changes['Evidence perlu ditinjau']['to']);

        // Tidak ada sisa nama kolom mentah di seluruh trail.
        foreach (AuditTrail::for($record->fresh()) as $entry) {
            $this->assertStringNotContainsString('_minutes', $entry['what']);
            $this->assertStringNotContainsString('overtime_date', $entry['what']);
        }
    }

    #[Test]
    public function perubahan_status_tercatat_sebagai_nilai_lama_dan_baru(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        $record = $this->logOvertime($user, '2026-03-19', '19:00', '23:30');
        $record->update(['status' => OvertimeStatus::Approved]);

        $trail = AuditTrail::for($record->fresh());

        $this->assertSame('updated', $trail[0]['event']);
        $this->assertSame('Diubah', $trail[0]['event_label']);
        $this->assertSame($user->name, $trail[0]['who']);
        $this->assertSame('Status', $trail[0]['changes'][0]['label']);
        $this->assertSame('Dicatat', $trail[0]['changes'][0]['from']);
        $this->assertSame('Disetujui', $trail[0]['changes'][0]['to']);
        $this->assertTrue($trail[0]['changes'][0]['has_from']);
        $this->assertSame('Status: Dicatat → Disetujui', $trail[0]['what']);
    }

    #[Test]
    public function event_pembuatan_diringkas_dan_tanpa_nilai_lama_kosong(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        $record = $this->logOvertime($user, '2026-03-19', '19:00', '23:30');
        $created = collect(AuditTrail::for($record->fresh()))->firstWhere('event', 'created');

        $this->assertSame('19 Maret 2026 · 19:00–23:30 · 4 jam 30 menit', $created['summary']);

        // Nilai "lama" pada pembuatan record tidak pernah bermakna, jadi tidak
        // boleh ikut dirender sebagai "— → nilai".
        foreach ($created['changes'] as $change) {
            $this->assertFalse($change['has_from'], "{$change['label']} seharusnya tanpa nilai lama");
        }
        $this->assertStringNotContainsString('→', $created['what']);
    }

    #[Test]
    public function evidence_dipendekkan_dan_tetap_menyimpan_tautan_aslinya(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        $record = $this->logOvertime($user, '2026-03-19', '19:00', '23:30');
        $record->update(['evidence_url' => 'https://timesheet.codeoffice.net/en/timesheet/182772/edit']);

        $evidence = $this->changesByLabel($record->fresh())['Evidence'];

        $this->assertSame('timesheet.codeoffice.net/…/182772/edit', $evidence['to']);
        $this->assertSame('https://timesheet.codeoffice.net/en/timesheet/182772/edit', $evidence['to_url']);
    }

    #[Test]
    public function field_tanpa_label_tetap_terbaca_dan_tidak_melempar(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $record = $this->logOvertime($user, '2026-03-19', '19:00', '23:30');

        // Field yang belum ada di peta label — misal kolom baru yang lupa didaftarkan.
        $activity = Activity::query()
            ->where('subject_id', $record->getKey())
            ->latest('id')
            ->first();
        $activity->attribute_changes = ['attributes' => ['kolom_baru_kami' => 'nilai'], 'old' => []];
        $activity->save();

        $changes = $this->changesByLabel($record->fresh());

        $this->assertArrayHasKey('Kolom Baru Kami', $changes);
        $this->assertSame('nilai', $changes['Kolom Baru Kami']['to']);
    }

    /**
     * Satu perubahan bisa tersebar di beberapa entri (observer menyimpan ulang
     * setelah kalkulator jalan); yang diuji nilai terbarunya per label.
     *
     * @return array<string, array<string, mixed>>
     */
    private function changesByLabel(OvertimeRecord $record): array
    {
        $map = [];

        foreach (AuditTrail::for($record) as $entry) {
            foreach ($entry['changes'] as $change) {
                $map[$change['label']] ??= $change;
            }
        }

        return $map;
    }
}
