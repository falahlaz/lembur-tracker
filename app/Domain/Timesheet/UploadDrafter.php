<?php

namespace App\Domain\Timesheet;

use App\Domain\Timesheet\Exceptions\InvalidWorkbook;
use App\Enums\UploadEntryStatus;
use App\Enums\UploadStatus;
use App\Models\TimesheetUpload;
use App\Models\TimesheetUploadEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Langkah 1: membaca berkas, memeriksa duplikat, menyimpannya sebagai draf.
 *
 * Tidak satu pun entri dikirim di sini — pratinjau harus bisa dilihat, ditutup,
 * dan dibuka lagi tanpa efek samping apa pun ke Kimai.
 *
 * Barisnya disimpan ke database, bukan ditahan sebagai properti Livewire seperti
 * ImportLembur. Bukan soal selera: pengirimannya berjalan di queue, dan job tidak
 * bisa membaca properti komponen. Begitu barisnya harus ada di database,
 * menahannya juga di memori hanya menciptakan sumber kebenaran kedua.
 */
class UploadDrafter
{
    public function __construct(
        private readonly WorkbookReader $reader,
        private readonly TimesheetWorkbookParser $parser,
        private readonly DuplicateDetector $duplicates,
    ) {}

    /** @throws InvalidWorkbook */
    public function draft(User $user, string $path, string $originalName): TimesheetUpload
    {
        $sheets = array_keys(TimesheetWorkbookParser::SHEETS);

        $book = $this->parser->parse(
            $this->reader->read($path, $sheets),
            $this->reader->sheetNames($path),
        );

        if ($book->isEmpty()) {
            throw new InvalidWorkbook(
                'Tidak ada satu pun sel berisi "Activity ID" di berkas ini, jadi tidak ada yang bisa dikirim.'
            );
        }

        $max = (int) config('kimai.upload_max_entries');

        if (count($book->entries) > $max) {
            // Pagar keras: berkas yang salah bentuk tidak boleh berubah menjadi
            // ribuan POST ke instance Kimai bersama.
            throw new InvalidWorkbook(
                'Berkas ini menghasilkan '.count($book->entries)." entri, melebihi batas {$max}. "
                .'Periksa lagi formatnya sebelum dikirim.'
            );
        }

        $check = $this->duplicates->mark($user, $book);

        return DB::transaction(function () use ($user, $book, $check, $originalName, $path) {
            // Satu draf per orang. Draf lama yang ditinggalkan dibatalkan, bukan
            // dihapus — supaya riwayatnya tetap jujur.
            TimesheetUpload::query()
                ->where('user_id', $user->id)
                ->draft()
                ->update(['status' => UploadStatus::Cancelled->value]);

            $issues = $book->issues;

            if (! $check->checked) {
                $issues[] = 'Duplikat tidak bisa diperiksa: '.$check->unavailableReason;
            }

            $upload = TimesheetUpload::create([
                'user_id' => $user->id,
                'original_filename' => $originalName,
                'file_hash' => @hash_file('sha256', $path) ?: null,
                'customer_id' => $book->customerId,
                'project_id' => $book->projectId ?? (int) config('kimai.default_project'),
                'status' => UploadStatus::Draft->value,
                'range_start' => $book->rangeStart()?->toDateString(),
                'range_end' => $book->rangeEnd()?->toDateString(),
                'count_parsed' => count($book->entries),
                'duplicates_checked' => $check->checked,
                'issues' => $issues,
            ]);

            foreach ($book->entries as $entry) {
                TimesheetUploadEntry::create([
                    'timesheet_upload_id' => $upload->id,
                    'user_id' => $user->id,
                    'sheet' => $entry->sheet,
                    'cell_ref' => $entry->cellRef,
                    'slot_label' => $entry->slotLabel,
                    'work_date' => $entry->workDate->toDateString(),
                    // Dikonversi ke UTC EKSPLISIT. Eloquent memformat objek tanggal
                    // menurut zona objek itu sendiri, tanpa mengonversinya lebih
                    // dulu; menyimpan 09:00+0700 apa adanya akan terbaca kembali
                    // sebagai 09:00 UTC alias 16:00 WIB.
                    'begin_at' => $entry->beginAt->utc(),
                    'end_at' => $entry->endAt->utc(),
                    'duration_minutes' => $entry->durationMinutes(),
                    'activity_id' => $entry->activityId,
                    'description' => $entry->description,
                    'tag' => $entry->tag,
                    'status' => $entry->isPostable()
                        ? UploadEntryStatus::Pending->value
                        : UploadEntryStatus::Skipped->value,
                    'skip_reason' => $entry->skipReason ?: ($entry->errors[0] ?? null),
                    'overridable' => $entry->overridable,
                    'conflicting_kimai_id' => $entry->conflictingKimaiId,
                ]);
            }

            $upload->forceFill(['count_skipped' => $upload->entries()
                ->where('status', UploadEntryStatus::Skipped->value)->count()])->save();

            // shouldBeStrict() menolak membaca kolom yang nilainya berasal dari
            // default database dan belum pernah dimuat.
            return $upload->refresh();
        });
    }

    /**
     * Mengembalikan entri yang bentroknya boleh ditimpa user ke antrean kirim.
     * Hanya yang overridable — bentrok di dalam berkas sendiri tidak pernah bisa.
     */
    public function includeOverridable(TimesheetUpload $upload): int
    {
        $jumlah = $upload->entries()
            ->where('status', UploadEntryStatus::Skipped->value)
            ->where('overridable', true)
            ->update([
                'status' => UploadEntryStatus::Pending->value,
                'skip_reason' => null,
            ]);

        $upload->forceFill(['count_skipped' => $upload->entries()
            ->where('status', UploadEntryStatus::Skipped->value)->count()])->save();

        return $jumlah;
    }

    public function cancel(TimesheetUpload $upload): void
    {
        $upload->forceFill(['status' => UploadStatus::Cancelled->value])->save();
    }
}
