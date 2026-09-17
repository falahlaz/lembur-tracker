<?php

namespace App\Domain\Timesheet;

use App\Domain\Kimai\Exceptions\KimaiException;
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
        private readonly KimaiCatalog $catalog,
        private readonly ActivityResolver $activities,
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

        $projectId = $book->projectId ?? (int) config('kimai.default_project');

        // Diresolusi SEBELUM pemeriksaan duplikat: entri yang activity-nya tidak
        // ketemu sudah tidak layak kirim, jadi tidak perlu ikut dibandingkan.
        $issuesActivity = $this->resolveActivities($user, $book->entries, $projectId);

        $check = $this->duplicates->mark($user, $book);

        return DB::transaction(function () use ($user, $book, $check, $originalName, $path, $projectId, $issuesActivity) {
            // Satu draf per orang. Draf lama yang ditinggalkan dibatalkan, bukan
            // dihapus — supaya riwayatnya tetap jujur.
            TimesheetUpload::query()
                ->where('user_id', $user->id)
                ->draft()
                ->update(['status' => UploadStatus::Cancelled->value]);

            $issues = array_merge($book->issues, $issuesActivity);

            if (! $check->checked) {
                $issues[] = 'Duplikat tidak bisa diperiksa: '.$check->unavailableReason;
            }

            $upload = TimesheetUpload::create([
                'user_id' => $user->id,
                'original_filename' => $originalName,
                'file_hash' => @hash_file('sha256', $path) ?: null,
                'customer_id' => $book->customerId,
                'project_id' => $projectId,
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
                    'activity_name' => $entry->activityName,
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
     * @param  array<int, ParsedEntry>  $entries
     * @return array<int, string>
     */
    private function resolveActivities(User $user, array $entries, int $projectId): array
    {
        $pakaiNama = array_filter($entries, fn (ParsedEntry $e) => $e->activityName !== null);

        if ($pakaiNama === []) {
            return [];
        }

        try {
            $daftar = $this->catalog->activities($user, $projectId);
        } catch (KimaiException $e) {
            // Jujur, bukan diam-diam lolos: tanpa daftar activity, tidak ada satu
            // pun nama yang bisa dipastikan benar.
            foreach ($pakaiNama as $entry) {
                $entry->skip('Daftar activity tidak bisa diambil dari Kimai.');
            }

            return ['Daftar activity tidak bisa diambil dari Kimai: '.$e->userMessage()];
        }

        $gagal = $this->activities->resolve($entries, $daftar, $this->projectLabel($user, $projectId));

        return $gagal > 0
            ? ["{$gagal} entri memakai nama activity yang tidak dikenali di project ini."]
            : [];
    }

    private function projectLabel(User $user, int $projectId): string
    {
        try {
            foreach ($this->catalog->projects($user) as $project) {
                if ($project['id'] === $projectId) {
                    return $project['name'];
                }
            }
        } catch (KimaiException) {
            // Label hanya untuk pesan kesalahan; id tetap memberi tahu yang perlu.
        }

        return "#{$projectId}";
    }

    /**
     * Ganti project sesudah pratinjau dibuat: nama activity yang sama bisa
     * menunjuk id yang berbeda di project lain.
     *
     * Bekerja dari baris yang sudah ada di database, jadi berkasnya TIDAK perlu
     * diunggah ulang — nama aslinya masih tersimpan di kolom activity_name.
     *
     * @return array{diresolusi: int, gagal: int}
     */
    public function reresolveActivities(TimesheetUpload $upload, int $projectId): array
    {
        $user = $upload->user;

        $rows = $upload->entries()->whereNotNull('activity_name')->get();

        if ($rows->isEmpty()) {
            $upload->forceFill(['project_id' => $projectId])->save();

            return ['diresolusi' => 0, 'gagal' => 0];
        }

        // Sengaja TIDAK ditangkap: pemanggil yang memutuskan apa yang ditampilkan
        // kalau Kimai sedang tidak terjangkau saat user mengganti project.
        $daftar = $this->catalog->activities($user, $projectId);

        $label = $this->projectLabel($user, $projectId);

        // Diperankan sebagai ParsedEntry supaya aturan pencocokannya persis sama
        // dengan jalur analisa — bukan salinan kedua yang bisa berbeda perlahan.
        $proxies = [];

        foreach ($rows as $row) {
            $proxies[$row->id] = new ParsedEntry(
                sheet: (string) $row->sheet,
                cellRef: (string) $row->cell_ref,
                slotLabel: (string) $row->slot_label,
                workDate: $row->work_date,
                beginAt: $row->begin_at,
                endAt: $row->end_at,
                activityId: null,
                activityName: (string) $row->activity_name,
                description: (string) $row->description,
                tag: $row->tag,
            );
        }

        $gagal = $this->activities->resolve(array_values($proxies), $daftar, $label);

        DB::transaction(function () use ($rows, $proxies, $upload, $projectId) {
            foreach ($rows as $row) {
                $proxy = $proxies[$row->id];

                $row->forceFill([
                    'activity_id' => $proxy->activityId,
                    'status' => $proxy->activityId !== null
                        ? UploadEntryStatus::Pending->value
                        : UploadEntryStatus::Skipped->value,
                    'skip_reason' => $proxy->skipReason,
                ])->save();
            }

            $upload->forceFill([
                'project_id' => $projectId,
                'count_skipped' => $upload->entries()
                    ->where('status', UploadEntryStatus::Skipped->value)->count(),
            ])->save();
        });

        return ['diresolusi' => $rows->count() - $gagal, 'gagal' => $gagal];
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
