<?php

namespace Tests\Feature\Domain;

use App\Domain\Timesheet\DuplicateDetector;
use App\Domain\Timesheet\ParsedEntry;
use App\Domain\Timesheet\ParsedWorkbook;
use App\Enums\UploadEntryStatus;
use App\Enums\UploadStatus;
use App\Models\TimesheetUpload;
use App\Models\TimesheetUploadEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Catatan lokal "sudah pernah diupload" hanya cadangan; Kimai sumber kebenaran. */
class DuplicateDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function book(): ParsedWorkbook
    {
        $zone = config('kimai.timezone');

        return new ParsedWorkbook(null, null, [new ParsedEntry(
            sheet: 'Overtime',
            cellRef: 'Overtime!B5',
            slotLabel: '6 PM - 7 PM',
            workDate: CarbonImmutable::parse('2026-11-03', $zone),
            beginAt: CarbonImmutable::parse('2026-11-03 18:00', $zone),
            endAt: CarbonImmutable::parse('2026-11-03 19:00', $zone),
            activityId: 25,
            activityName: null,
            description: 'x',
            tag: 'Overtime',
        )]);
    }

    private function sudahPernahTerkirim(User $user, ParsedWorkbook $book): void
    {
        $e = $book->entries[0];
        $upload = TimesheetUpload::create([
            'user_id' => $user->id, 'source' => 'upload', 'original_filename' => 'x.xlsx',
            'file_hash' => 'h', 'status' => UploadStatus::Success->value,
        ]);

        TimesheetUploadEntry::unguarded(fn () => TimesheetUploadEntry::create([
            'timesheet_upload_id' => $upload->id, 'user_id' => $user->id,
            'sheet' => 'Overtime', 'cell_ref' => 'B5', 'slot_label' => 'x',
            'work_date' => '2026-11-03', 'begin_at' => $e->beginAt->utc(), 'end_at' => $e->endAt->utc(),
            'duration_minutes' => 60, 'activity_id' => 25, 'description' => 'x', 'tag' => 'Overtime',
            'status' => UploadEntryStatus::Posted->value, 'kimai_timesheet_id' => 900001,
        ]));
    }

    #[Test]
    public function catatan_lokal_diabaikan_saat_entrinya_sudah_dihapus_di_kimai(): void
    {
        $user = $this->kimaiUser();
        $book = $this->book();
        $this->sudahPernahTerkirim($user, $book);
        $this->fakeKimai([]);

        $hasil = app(DuplicateDetector::class)->mark($user, $book);

        $this->assertTrue($hasil->checked);
        $this->assertSame(0, $hasil->total());
        $this->assertNull($book->entries[0]->skipReason);
    }

    #[Test]
    public function entri_yang_masih_ada_di_kimai_tetap_dilewati(): void
    {
        $user = $this->kimaiUser();
        $book = $this->book();
        $this->sudahPernahTerkirim($user, $book);
        $this->fakeKimai([$this->kimaiSlot(900001, '2026-11-03', '18:00', '19:00')]);

        app(DuplicateDetector::class)->mark($user, $book);

        $this->assertStringContainsString('Sudah ada di Kimai', (string) $book->entries[0]->skipReason);
    }

    #[Test]
    public function catatan_lokal_dipakai_saat_kimai_tidak_terjangkau(): void
    {
        $user = $this->kimaiUser();
        $book = $this->book();
        $this->sudahPernahTerkirim($user, $book);
        $this->fakeKimai([]);
        $this->kimaiGetStatus = 503;

        $hasil = app(DuplicateDetector::class)->mark($user, $book);

        $this->assertFalse($hasil->checked);
        $this->assertSame(1, $hasil->localConflicts);
        $this->assertSame('Sudah pernah diupload dari sini.', $book->entries[0]->skipReason);
    }
}
