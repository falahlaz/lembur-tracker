<?php

namespace Tests\Feature\Domain;

use App\Domain\Kimai\Exceptions\KimaiTokenInvalid;
use App\Domain\Kimai\KimaiConnection;
use App\Domain\Kimai\KimaiSynchronizer;
use App\Enums\OvertimeStatus;
use App\Enums\Source;
use App\Enums\SyncAction;
use App\Enums\SyncStatus;
use App\Enums\Tier;
use App\Jobs\SyncKimaiTimesheets;
use App\Models\OvertimeRecord;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** PRD Sync Kimai §11 — skenario uji wajib T-1 s/d T-12, plus SR-2 dan SY-16. */
class KimaiSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-09-13');
        $this->baselineRule();
    }

    private function sync($user): SyncRun
    {
        return app(KimaiSynchronizer::class)->run($user);
    }

    #[Test]
    public function t_1_sync_dua_kali_pada_rentang_sama_tidak_menduplikasi(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry()]);

        $first = $this->sync($user);

        $this->assertSame(1, $first->count_created);
        $this->assertSame(1, OvertimeRecord::count());

        $this->fakeKimai([$this->kimaiEntry()]);
        $second = $this->sync($user->refresh());

        $this->assertSame(0, $second->count_created);
        $this->assertSame(1, OvertimeRecord::count());
        $this->assertSame(
            SyncAction::Unchanged,
            $second->items()->first()->action,
        );
    }

    #[Test]
    public function t_2_entri_lintas_tengah_malam_masuk_ke_tanggal_begin(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry([
            'begin' => '2026-09-03T21:00:00+0700',
            'end' => '2026-09-04T02:00:00+0700',
        ])]);

        $this->sync($user);

        $record = OvertimeRecord::sole();

        // BR-03/SY-11 — diikatkan ke tanggal mulai, bukan dipecah dua hari.
        $this->assertSame('2026-09-03', $record->overtime_date->toDateString());
        $this->assertSame('21:00', substr((string) $record->start_time, 0, 5));
        $this->assertSame('02:00', substr((string) $record->end_time, 0, 5));
        $this->assertSame(300, $record->duration_raw_minutes);
    }

    #[Test]
    public function t_3_tiga_entri_di_tanggal_sama_menghasilkan_satu_entitlement(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([
            $this->kimaiEntry(['id' => 1, 'begin' => '2026-09-03T08:00:00+0700', 'end' => '2026-09-03T11:00:00+0700']),
            $this->kimaiEntry(['id' => 2, 'begin' => '2026-09-03T11:00:00+0700', 'end' => '2026-09-03T14:00:00+0700']),
            $this->kimaiEntry(['id' => 3, 'begin' => '2026-09-03T14:00:00+0700', 'end' => '2026-09-03T17:00:00+0700']),
        ]);

        $this->sync($user);

        // SY-08 — tiga record, jejak audit per baris timesheet tetap utuh.
        $this->assertSame(3, OvertimeRecord::count());

        // BR-02/BR-07 — total 9 jam menghasilkan tier 2, dan hanya SATU uang makan.
        $records = OvertimeRecord::orderBy('start_time')->get();
        $this->assertTrue($records->every(fn ($r) => $r->tier === Tier::Tier2));
        $this->assertSame(1, $records->where('meal_allowance_amount', '>', 0)->count());
        $this->assertSame(100_000, $records->first()->meal_allowance_amount);
    }

    #[Test]
    public function t_4_break_dipotong_dan_tier_dihitung_dari_duration(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry([
            'begin' => '2026-09-03T19:00:00+0700',
            'end' => '2026-09-03T23:00:00+0700',
            'break' => 1800,
            // SY-10 — `duration` Kimai SUDAH bersih dari break: 4 jam − 30 menit.
            'duration' => 12600,
        ])]);

        $this->sync($user);

        $record = OvertimeRecord::sole();

        $this->assertSame(30, $record->break_minutes);
        // Selisih jam 240 menit, tetapi yang dipakai adalah 210.
        $this->assertSame(210, $record->duration_raw_minutes);
        $this->assertSame(Tier::None, $record->tier, 'Belum 4 jam efektif, jadi belum berhak.');
    }

    #[Test]
    public function t_5_record_yang_diedit_user_tidak_pernah_ditimpa(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry()]);
        $this->sync($user);

        $record = OvertimeRecord::sole();
        $record->work_description = 'Diubah manual oleh user';
        $record->save();

        $this->assertTrue($record->refresh()->locally_modified);

        $this->fakeKimai([$this->kimaiEntry(['description' => 'Deskripsi baru dari Kimai'])]);
        $run = $this->sync($user->refresh());

        $this->assertSame('Diubah manual oleh user', $record->refresh()->work_description);
        $this->assertSame(1, $run->count_skipped);
        $this->assertSame('ada perubahan lokal', $run->items()->first()->reason);
    }

    #[Test]
    public function t_6_entri_yang_bentrok_dengan_record_manual_tidak_diimpor(): void
    {
        $user = $this->kimaiUser();
        $manual = $this->logOvertime($user, '2026-09-03', '20:30', '23:00');

        $this->fakeKimai([$this->kimaiEntry()]); // 20:00–22:00, bertabrakan

        $run = $this->sync($user);

        $this->assertSame(1, OvertimeRecord::count(), 'Hanya record manual yang ada.');
        $this->assertSame(1, $run->count_skipped);
        // SY-15 — ID record yang bentrok ikut dicatat supaya user bisa memutuskan.
        $this->assertSame("bentrok dengan catatan manual #{$manual->id}", $run->items()->first()->reason);
    }

    #[Test]
    public function t_7_timesheet_yang_masih_berjalan_dilewati_tanpa_error(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry(['end' => null, 'duration' => 0])]);

        $run = $this->sync($user);

        $this->assertSame(0, OvertimeRecord::count());
        $this->assertSame(0, $run->count_failed);
        // SY-06 — dilewati diam-diam, tidak dicatat sebagai apa pun.
        $this->assertSame(0, $run->items()->count());
        $this->assertSame(SyncStatus::Success, $run->status);

        // Sync berikutnya, setelah timer dihentikan, entri itu masuk.
        $this->fakeKimai([$this->kimaiEntry()]);
        $this->sync($user->refresh());

        $this->assertSame(1, OvertimeRecord::count());
    }

    #[Test]
    public function t_8_token_dicabut_membuat_run_gagal_dan_mematikan_auto_sync(): void
    {
        $user = $this->kimaiUser();
        $user->kimai_auto_sync = true;
        $user->save();

        Http::fake(['*/api/timesheets*' => Http::response(['message' => 'Unauthorized'], 401)]);

        try {
            app(SyncKimaiTimesheets::class, ['user' => $user->refresh()])
                ->handle(app(KimaiSynchronizer::class), app(KimaiConnection::class));
        } catch (KimaiTokenInvalid) {
            // SY-22 — dilempar supaya queue menandai job gagal, tanpa retry.
        }

        $run = SyncRun::sole();
        $this->assertSame(SyncStatus::Failed, $run->status);
        $this->assertStringContainsString('API key kamu sudah tidak berlaku', $run->error_message);

        $user->refresh();
        $this->assertFalse($user->kimai_auto_sync);
        $this->assertNull($user->kimai_token_valid_at);
    }

    #[Test]
    public function t_9_dua_ratus_lima_puluh_entri_terambil_seluruhnya(): void
    {
        $user = $this->kimaiUser();

        $entries = [];
        for ($i = 0; $i < 250; $i++) {
            // 25 tanggal berturut-turut sejak 19 Agustus, masing-masing 10 sesi
            // sejam yang tidak saling bertabrakan (SY-15 tidak ikut campur).
            $date = CarbonImmutable::parse('2026-08-19')->addDays(intdiv($i, 10))->toDateString();
            $hour = 8 + ($i % 10);
            $entries[] = $this->kimaiEntry([
                'id' => 1000 + $i,
                'begin' => sprintf('%sT%02d:00:00+0700', $date, $hour),
                'end' => sprintf('%sT%02d:00:00+0700', $date, $hour + 1),
            ]);
        }

        $this->fakeKimai($entries);
        $run = $this->sync($user);

        // Tiga halaman (100 + 100 + 50), tidak ada yang hilang.
        $this->assertSame(250, $run->count_fetched);
        $this->assertSame(250, $run->count_created);
        $this->assertSame(250, OvertimeRecord::count());
    }

    #[Test]
    public function t_10_dua_klik_beruntun_hanya_membentuk_satu_run(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry()]);

        // SY-18 — job kedua datang saat job pertama masih memegang kunci.
        $lock = Cache::lock(SyncKimaiTimesheets::lockKey($user), 600);
        $this->assertTrue($lock->get());

        app(SyncKimaiTimesheets::class, ['user' => $user])
            ->handle(app(KimaiSynchronizer::class), app(KimaiConnection::class));

        $this->assertSame(0, SyncRun::count(), 'Job kedua ditolak kunci, tidak ada run terbentuk.');

        $lock->release();
    }

    #[Test]
    public function t_11_sync_pertama_dimulai_dari_awal_periode_payroll(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([]);

        $run = $this->sync($user);

        // BR-09 — periode berjalan untuk 13 September adalah 19 Agustus – 18 September.
        $this->assertSame('2026-08-19', $run->range_start->toDateString());
    }

    #[Test]
    public function t_12_tag_huruf_kecil_tetap_terimpor(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry(['tags' => ['overtime']])]);

        $this->sync($user);

        $this->assertSame(1, OvertimeRecord::count());
    }

    #[Test]
    public function sy_05_entri_tanpa_tag_overtime_ditahan_filter_aplikasi(): void
    {
        $user = $this->kimaiUser();

        // Filter server sengaja "bocor": entri tanpa tag Overtime ikut terkirim.
        $this->fakeKimai([
            $this->kimaiEntry(['id' => 1, 'tags' => ['Meeting']]),
            $this->kimaiEntry(['id' => 2, 'tags' => ['OVERTIME ']]),
        ]);

        $this->sync($user);

        $this->assertSame(1, OvertimeRecord::count());
        $this->assertSame(2, OvertimeRecord::sole()->kimai_timesheet_id);
    }

    #[Test]
    public function sy_07_durasi_nol_dilewati_dengan_alasan(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry(['duration' => 0])]);

        $run = $this->sync($user);

        $this->assertSame(0, OvertimeRecord::count());
        $this->assertSame('durasi tidak valid', $run->items()->first()->reason);
    }

    #[Test]
    public function sy_09_record_hasil_sync_membawa_referensi_dan_deep_link(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry()]);

        $this->sync($user);
        $record = OvertimeRecord::sole();

        $this->assertSame(Source::Kimai, $record->source);
        $this->assertSame(182772, $record->kimai_timesheet_id);
        $this->assertSame(105, $record->kimai_project_id);
        $this->assertSame(9, $record->kimai_activity_id);
        $this->assertSame(OvertimeStatus::Recorded, $record->status);
        // SY-12 — deep link sebagai pengisi sementara, plus penandanya.
        $this->assertSame(
            'https://timesheet.codeoffice.net/en/timesheet/182772/edit',
            $record->evidence_url,
        );
        $this->assertTrue($record->evidence_needs_review);
    }

    #[Test]
    public function sy_09_deskripsi_kosong_diganti_teks_yang_terbaca(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry(['description' => ''])]);

        $this->sync($user);

        $this->assertSame('Lembur dari Kimai #182772', OvertimeRecord::sole()->work_description);
    }

    #[Test]
    public function sy_14_record_yang_sudah_diajukan_tidak_disentuh(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry()]);
        $this->sync($user);

        $record = OvertimeRecord::sole();
        // Ditulis lewat query builder supaya observer tidak menyalakan
        // locally_modified — yang diuji di sini murni cabang status.
        OvertimeRecord::query()->whereKey($record->id)
            ->update(['status' => OvertimeStatus::Submitted->value]);

        $this->fakeKimai([$this->kimaiEntry(['description' => 'Berubah di Kimai'])]);
        $run = $this->sync($user->refresh());

        $this->assertSame('sudah diajukan/disetujui', $run->items()->first()->reason);
    }

    #[Test]
    public function sy_16_offset_selain_wib_dikonversi_sebelum_tanggal_diambil(): void
    {
        $user = $this->kimaiUser();

        // 2026-09-03 20:30 UTC = 2026-09-04 03:30 WIB. Tanpa konversi, record ini
        // akan mendarat di tanggal 3 — inilah bug "lembur masuk ke tanggal yang salah".
        $this->fakeKimai([$this->kimaiEntry([
            'begin' => '2026-09-03T20:30:00+0000',
            'end' => '2026-09-03T23:30:00+0000',
        ])]);

        $this->sync($user);
        $record = OvertimeRecord::sole();

        $this->assertSame('2026-09-04', $record->overtime_date->toDateString());
        $this->assertSame('03:30', substr((string) $record->start_time, 0, 5));
    }

    #[Test]
    public function sy_10_durasi_kimai_bertahan_setelah_record_manual_disimpan_di_tanggal_sama(): void
    {
        // Regresi: OvertimeDayCalculator::recalculate() menghitung ulang durasi
        // SELURUH record di satu tanggal. Sebelum rawMinutes() disatukan, angka
        // dari Kimai tertimpa diam-diam begitu record lain di tanggal yang sama
        // tersimpan — tanpa satu pun pesan error.
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry([
            'begin' => '2026-09-03T19:00:00+0700',
            'end' => '2026-09-03T23:00:00+0700',
            'break' => 1800,
            'duration' => 12600,
        ])]);

        $this->sync($user);
        $this->assertSame(210, OvertimeRecord::sole()->duration_raw_minutes);

        // Record manual di tanggal yang sama memicu recalculate() untuk keduanya.
        $this->logOvertime($user, '2026-09-03', '06:00', '08:00');

        $kimai = OvertimeRecord::where('source', Source::Kimai->value)->sole();
        $this->assertSame(210, $kimai->duration_raw_minutes, 'Durasi Kimai tertimpa oleh kalkulator.');
    }

    #[Test]
    public function sr_2_token_tidak_pernah_muncul_di_log_saat_gagal(): void
    {
        $token = 'rahasia-jangan-bocor-9999';
        $user = $this->kimaiUser(token: $token);

        $captured = [];
        Log::listen(function ($message) use (&$captured) {
            $captured[] = $message->message.' '.json_encode($message->context);
        });

        Http::fake(['*/api/timesheets*' => Http::response('gagal', 500)]);

        $thrown = null;
        try {
            $this->sync($user);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        // §9 — exception dibungkus ulang justru supaya RequestException bawaan
        // Laravel, yang membawa header Authorization, tidak pernah lolos.
        $this->assertStringNotContainsString($token, (string) $thrown);

        foreach ($captured as $line) {
            $this->assertStringNotContainsString($token, $line);
        }
    }

    #[Test]
    public function sy_19_satu_entri_gagal_tidak_menggagalkan_seluruh_batch(): void
    {
        $user = $this->kimaiUser();

        $this->fakeKimai([
            // ID duplikat dalam satu batch: yang kedua melanggar unique (SY-13).
            $this->kimaiEntry(['id' => 7, 'begin' => '2026-09-03T08:00:00+0700', 'end' => '2026-09-03T10:00:00+0700']),
            $this->kimaiEntry(['id' => 8, 'begin' => '2026-09-04T08:00:00+0700', 'end' => '2026-09-04T10:00:00+0700']),
        ]);

        $run = $this->sync($user);

        $this->assertSame(2, $run->count_created);
        $this->assertSame(SyncStatus::Success, $run->status);
        $this->assertSame(2, SyncRunItem::count());
    }

    #[Test]
    public function f_13_riwayat_hanya_menyimpan_tiga_puluh_run_terakhir(): void
    {
        $user = $this->kimaiUser();

        for ($i = 0; $i < 32; $i++) {
            $this->fakeKimai([]);
            $this->sync($user->refresh());
        }

        $this->assertSame(SyncRun::KEEP_PER_USER, SyncRun::where('user_id', $user->id)->count());
    }
}
