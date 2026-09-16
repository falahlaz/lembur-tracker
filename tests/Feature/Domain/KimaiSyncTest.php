<?php

namespace Tests\Feature\Domain;

use App\Domain\Kimai\Exceptions\KimaiTokenInvalid;
use App\Domain\Kimai\KimaiClient;
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

        // 25 HARI KERJA, masing-masing 10 sesi sejam jam 08:00–18:00 yang tidak saling
        // bertabrakan (SY-15 tidak ikut campur). Weekend sengaja dilewati: di Sabtu
        // dan Minggu seluruh hari adalah satu jendela lembur (SY-23), sehingga sepuluh
        // entri sehari akan melebur jadi satu record dan cacahnya tidak lagi bulat.
        // Yang diuji di sini paginasi, bukan pengelompokan.
        $dates = [];
        $cursor = CarbonImmutable::parse('2026-09-11');

        while (count($dates) < 25) {
            if (! $cursor->isSaturday() && ! $cursor->isSunday()) {
                $dates[] = $cursor->toDateString();
            }

            $cursor = $cursor->subDay();
        }

        $entries = [];
        for ($i = 0; $i < 250; $i++) {
            $date = $dates[intdiv($i, 10)];
            // Semuanya mulai sebelum jam pulang, jadi tidak ada satu pun yang MEMBUKA
            // jendela lembur malam — setiap entri berdiri sendiri.
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
    public function bagian_10_run_yang_macet_ditandai_gagal_agar_tombol_hidup_lagi(): void
    {
        $user = $this->kimaiUser();

        // Run yang tertinggal berstatus `running` karena job mati diam-diam.
        $stale = SyncRun::query()->create([
            'user_id' => $user->id,
            'status' => SyncStatus::Running,
            'range_start' => now()->subDay(),
            'range_end' => now(),
        ]);
        $stale->created_at = now()->subMinutes(30);
        $stale->save();

        KimaiSynchronizer::failStaleRuns($user);

        $this->assertSame(SyncStatus::Failed, $stale->refresh()->status);
        $this->assertNotNull($stale->finished_at);
    }

    #[Test]
    public function bagian_10_kesalahan_tak_terduga_tetap_menutup_run(): void
    {
        $user = $this->kimaiUser();

        // Kegagalan di luar jalur KimaiException — bug, bukan masalah jaringan.
        $this->app->bind(KimaiClient::class, fn () => new class extends KimaiClient
        {
            public function timesheets(string $token, $begin, $end): array
            {
                throw new \RuntimeException('boom');
            }
        });

        $thrown = null;
        try {
            $this->sync($user);
        } catch (\Throwable $e) {
            // Dibiarkan naik supaya queue menandai job gagal.
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);

        $run = SyncRun::latest('id')->first();
        $this->assertNotNull($run);
        // Kalau run tertinggal `running`, tombol sync terkunci permanen.
        $this->assertSame(SyncStatus::Failed, $run->status);
        $this->assertNotNull($run->finished_at);
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

    // =====================================================================
    // SY-23 s/d SY-25 — satu sesi lembur = satu record, meski Kimai memecahnya
    // jadi beberapa timesheet karena batas 2 jam per entri.
    // =====================================================================

    private const WEDNESDAY = '2026-09-09';

    private const THURSDAY = '2026-09-10';

    private const FRIDAY = '2026-09-11';

    private const SATURDAY = '2026-09-12';

    private const SUNDAY = '2026-09-06';

    private const MONDAY = '2026-09-07';

    private const TUESDAY = '2026-09-08';

    #[Test]
    public function sy_23_empat_entri_lintas_tengah_malam_jadi_satu_lembur(): void
    {
        $user = $this->kimaiUser();

        $this->fakeKimai([
            $this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00'),
            $this->kimaiSlot(2, self::WEDNESDAY, '20:00', '22:00'),
            $this->kimaiSlot(3, self::WEDNESDAY, '22:00', '00:00'),
            $this->kimaiSlot(4, self::THURSDAY, '00:00', '02:00'),
        ]);

        $this->sync($user);

        // Inilah bug yang diperbaiki: dulu ini jadi 3 record di Rabu (6 jam, Tier1)
        // plus 1 record di Kamis (2 jam) — dua-duanya salah.
        $this->assertSame(1, OvertimeRecord::count());

        $record = OvertimeRecord::first();
        $this->assertSame(self::WEDNESDAY, $record->overtime_date->toDateString());
        $this->assertSame('18:00', substr((string) $record->start_time, 0, 5));
        $this->assertSame('02:00', substr((string) $record->end_time, 0, 5));
        $this->assertSame(480, $record->duration_raw_minutes);
        $this->assertSame(Tier::Tier2, $record->tier);
        $this->assertSame(100_000, $record->meal_allowance_amount);
        $this->assertSame([1, 2, 3, 4], $record->kimaiEntries->pluck('kimai_timesheet_id')->all());
    }

    #[Test]
    public function sy_24_jeda_antar_entri_tercatat_sebagai_break(): void
    {
        $user = $this->kimaiUser();

        $this->fakeKimai([
            $this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00'),
            $this->kimaiSlot(2, self::WEDNESDAY, '21:00', '23:00'),
        ]);

        $this->sync($user);

        $record = OvertimeRecord::first();
        $this->assertSame('18:00', substr((string) $record->start_time, 0, 5));
        $this->assertSame('23:00', substr((string) $record->end_time, 0, 5));
        // SY-10 — durasi memang lebih pendek dari selisih jam, dan `break_minutes`
        // yang menjelaskan selisihnya.
        $this->assertSame(60, $record->break_minutes);
        $this->assertSame(240, $record->duration_raw_minutes);
    }

    #[Test]
    public function sy_23_weekend_seluruh_hari_jadi_satu_lembur(): void
    {
        $user = $this->kimaiUser();

        $this->fakeKimai([
            $this->kimaiSlot(1, self::SATURDAY, '09:00', '11:00'),
            $this->kimaiSlot(2, self::SATURDAY, '13:00', '15:00'),
        ]);

        $this->sync($user);

        $this->assertSame(1, OvertimeRecord::count());
        $record = OvertimeRecord::first();
        $this->assertSame(self::SATURDAY, $record->overtime_date->toDateString());
        $this->assertSame(240, $record->duration_raw_minutes);
        $this->assertSame(120, $record->break_minutes);
    }

    #[Test]
    public function sy_23_jumat_malam_lewat_tengah_malam_tetap_milik_jumat(): void
    {
        $user = $this->kimaiUser();

        $this->fakeKimai([
            $this->kimaiSlot(1, self::FRIDAY, '18:00', '20:00'),
            $this->kimaiSlot(2, self::SATURDAY, '00:00', '03:00'),
        ]);

        $this->sync($user);

        $this->assertSame(1, OvertimeRecord::count());
        $this->assertSame(self::FRIDAY, OvertimeRecord::first()->overtime_date->toDateString());
    }

    #[Test]
    public function sy_23_minggu_malam_ke_senin_jadi_dua_lembur(): void
    {
        $user = $this->kimaiUser();

        $this->fakeKimai([
            $this->kimaiSlot(1, self::SUNDAY, '20:00', '22:00'),
            $this->kimaiSlot(2, self::MONDAY, '00:00', '02:00'),
        ]);

        $this->sync($user);

        $this->assertSame(
            [self::SUNDAY, self::MONDAY],
            OvertimeRecord::orderBy('overtime_date')->get()
                ->map(fn ($r) => $r->overtime_date->toDateString())->all(),
        );
    }

    #[Test]
    public function sy_23_entri_siang_hari_kerja_tetap_jadi_record_sendiri(): void
    {
        $user = $this->kimaiUser();

        $this->fakeKimai([
            $this->kimaiSlot(1, self::TUESDAY, '14:00', '16:00'),
            $this->kimaiSlot(2, self::TUESDAY, '19:00', '21:00'),
        ]);

        $this->sync($user);

        $this->assertSame(2, OvertimeRecord::count());
        $this->assertSame(
            ['14:00', '19:00'],
            OvertimeRecord::orderBy('start_time')->get()
                ->map(fn ($r) => substr((string) $r->start_time, 0, 5))->all(),
        );
    }

    #[Test]
    public function sy_25_sesi_separuh_dilanjutkan_pada_sync_berikutnya(): void
    {
        $user = $this->kimaiUser();

        // Sync pertama jalan saat orangnya masih bekerja: baru satu entri yang ada.
        $this->fakeKimai([$this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00')]);
        $this->sync($user);

        $this->fakeKimai([
            $this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00'),
            $this->kimaiSlot(2, self::WEDNESDAY, '20:00', '22:00'),
            $this->kimaiSlot(3, self::WEDNESDAY, '22:00', '00:00'),
        ]);
        $run = $this->sync($user->refresh());

        // Recordnya MELUAS, bukan lahir kedua kalinya.
        $this->assertSame(1, OvertimeRecord::count());
        $record = OvertimeRecord::first();
        $this->assertSame('00:00', substr((string) $record->end_time, 0, 5));
        $this->assertSame(360, $record->duration_raw_minutes);
        $this->assertSame(0, $run->count_created);
        $this->assertSame(1, $run->count_updated);
    }

    #[Test]
    public function sy_25_entri_dini_hari_menyusul_ke_sesi_yang_sudah_tersimpan(): void
    {
        // Jam dinding dimajukan ke Kamis pagi, supaya watermark sync pertama benar-benar
        // mendarat di tanggal setelah jendela lembur itu dibuka — persis keadaan yang
        // membuat pembukanya keluar dari jangkauan fetch berikutnya.
        $this->freezeDate(self::THURSDAY);

        $user = $this->kimaiUser();

        $this->fakeKimai([$this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00')]);
        $this->sync($user);
        $this->assertSame(self::THURSDAY, $user->refresh()->kimai_synced_through->toDateString());

        // Sync berikutnya hanya membawa entri dini hari Kamis — pembukanya tidak ikut
        // ditarik lagi. Tanpa `knownGroupKeys`, lembur ini akan salah mendarat sebagai
        // record sendiri di hari Kamis.
        $this->fakeKimai([$this->kimaiSlot(2, self::THURSDAY, '01:00', '03:00')]);
        $this->sync($user->refresh());

        $this->assertSame(1, OvertimeRecord::count());
        $record = OvertimeRecord::first();
        $this->assertSame(self::WEDNESDAY, $record->overtime_date->toDateString());
        $this->assertSame('03:00', substr((string) $record->end_time, 0, 5));
        $this->assertSame([1, 2], $record->kimaiEntries->pluck('kimai_timesheet_id')->all());
    }

    #[Test]
    public function sy_25_anggota_tersimpan_dan_anggota_baru_tetap_urut_jam(): void
    {
        $user = $this->kimaiUser();

        $this->fakeKimai([$this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00')]);
        $this->sync($user);

        // Sync kedua hanya membawa entri LANJUTANNYA; yang jam 18:00 hanya ada di
        // database. Keduanya harus tetap terbaca sebagai 18:00 lebih dulu — kalau
        // urutannya terbalik, jam mulai dan jam selesai record jadi sama besar dan
        // lemburnya terbaca nol.
        $this->fakeKimai([$this->kimaiSlot(2, self::WEDNESDAY, '20:00', '22:00')]);
        $this->sync($user->refresh());

        $record = OvertimeRecord::first();
        $this->assertSame('18:00', substr((string) $record->start_time, 0, 5));
        $this->assertSame('22:00', substr((string) $record->end_time, 0, 5));
        $this->assertSame(240, $record->duration_raw_minutes);
        $this->assertSame(0, $record->break_minutes);
    }

    #[Test]
    public function sy_25_entri_yang_digeser_keluar_jendela_pindah_ke_sesinya_sendiri(): void
    {
        $user = $this->kimaiUser();

        $this->fakeKimai([
            $this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00'),
            $this->kimaiSlot(2, self::WEDNESDAY, '20:00', '22:00'),
        ]);
        $this->sync($user);
        $this->assertSame(1, OvertimeRecord::count());

        // Entri kedua diperbaiki di Kimai jadi siang hari — ia keluar dari jendela
        // lembur malam dan sekarang berdiri sendiri.
        $this->fakeKimai([
            $this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00'),
            $this->kimaiSlot(2, self::WEDNESDAY, '13:00', '15:00'),
        ]);
        $this->sync($user->refresh());

        // Dua record, dan entri kedua hanya menempel di salah satunya. Kalau ia
        // tertinggal di record lama, lemburnya terhitung dua kali (BR-02).
        $this->assertSame(2, OvertimeRecord::count());

        $malam = OvertimeRecord::where('kimai_group_key', 'we:'.self::WEDNESDAY)->firstOrFail();
        $this->assertSame([1], $malam->kimaiEntries->pluck('kimai_timesheet_id')->all());
        $this->assertSame('20:00', substr((string) $malam->end_time, 0, 5));
        $this->assertSame(120, $malam->duration_raw_minutes);

        $siang = OvertimeRecord::where('kimai_group_key', 'ts:2')->firstOrFail();
        $this->assertSame([2], $siang->kimaiEntries->pluck('kimai_timesheet_id')->all());
        $this->assertSame('13:00', substr((string) $siang->start_time, 0, 5));
    }

    #[Test]
    public function sy_15_bentrok_manual_melewatkan_seluruh_sesi(): void
    {
        $user = $this->kimaiUser();
        $manual = $this->logOvertime($user, self::WEDNESDAY, '19:00', '20:00');

        $this->fakeKimai([
            $this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00'),
            $this->kimaiSlot(2, self::WEDNESDAY, '20:00', '22:00'),
        ]);
        $run = $this->sync($user);

        // Satu sesi, satu keputusan: keduanya dilewati, bukan hanya yang bertabrakan.
        $this->assertSame(1, OvertimeRecord::count());
        $this->assertSame(2, $run->items()->where('action', SyncAction::Skipped)->count());
        $this->assertStringContainsString(
            "bentrok dengan catatan manual #{$manual->id}",
            (string) $run->items()->first()->reason,
        );
    }

    #[Test]
    public function sy_14_perubahan_lokal_membekukan_seluruh_sesi(): void
    {
        $user = $this->kimaiUser();

        $this->fakeKimai([$this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00')]);
        $this->sync($user);

        $record = OvertimeRecord::first();
        $record->work_description = 'Ditulis ulang oleh user';
        $record->save();
        $this->assertTrue($record->refresh()->locally_modified);

        $this->fakeKimai([
            $this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00'),
            $this->kimaiSlot(2, self::WEDNESDAY, '20:00', '22:00'),
        ]);
        $run = $this->sync($user->refresh());

        // Anggota baru pun tidak masuk: menambahkannya berarti mengubah jam pada
        // catatan yang sudah disentuh manusia.
        $this->assertSame(1, OvertimeRecord::count());
        $this->assertSame('20:00', substr((string) $record->refresh()->end_time, 0, 5));
        $this->assertSame('Ditulis ulang oleh user', $record->work_description);
        $this->assertSame('ada perubahan lokal', $run->items()->first()->reason);
    }

    #[Test]
    public function sy_23_cacah_run_menyebut_lembur_bukan_timesheet(): void
    {
        $user = $this->kimaiUser();

        $this->fakeKimai([
            $this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00'),
            $this->kimaiSlot(2, self::WEDNESDAY, '20:00', '22:00'),
            $this->kimaiSlot(3, self::WEDNESDAY, '22:00', '00:00'),
            $this->kimaiSlot(4, self::THURSDAY, '00:00', '02:00'),
        ]);
        $run = $this->sync($user);

        // F-13 — "4 lembur baru" untuk satu lembur adalah kebohongan yang bikin panik.
        $this->assertSame(4, $run->count_fetched);
        $this->assertSame(1, $run->count_created);
        $this->assertStringContainsString('1 lembur baru', $run->summary());
        // Jejak per timesheet tetap utuh: empat baris menunjuk satu record yang sama.
        $this->assertSame(4, SyncRunItem::where('sync_run_id', $run->id)->count());
        $this->assertSame(1, SyncRunItem::where('sync_run_id', $run->id)
            ->distinct()->count('overtime_record_id'));
    }

    #[Test]
    public function sy_23_record_warisan_sync_lama_diserap_bukan_dibiarkan_bentrok(): void
    {
        $user = $this->kimaiUser();

        // Record hasil sync 1:1 sebelum pengelompokan ada: tidak punya kunci grup
        // maupun baris penghubung.
        $legacy = OvertimeRecord::query()->create([
            'user_id' => $user->id,
            'overtime_date' => self::WEDNESDAY,
            'start_time' => '18:00',
            'end_time' => '20:00',
            'work_description' => 'Lembur dari Kimai #1',
            'evidence_url' => 'https://timesheet.codeoffice.net/en/timesheet/1/edit',
            'status' => OvertimeStatus::Recorded,
            'source' => Source::Kimai,
            'kimai_timesheet_id' => 1,
        ]);

        $this->fakeKimai([
            $this->kimaiSlot(1, self::WEDNESDAY, '18:00', '20:00'),
            $this->kimaiSlot(2, self::WEDNESDAY, '20:00', '22:00'),
        ]);
        $this->sync($user);

        // Diserap ke dalam sesinya, bukan dilewati sebagai "bentrok".
        $this->assertSame(1, OvertimeRecord::count());
        $record = OvertimeRecord::first();
        $this->assertSame($legacy->id, $record->id, 'Record lama dipertahankan, bukan dibuat ulang.');
        $this->assertSame('22:00', substr((string) $record->end_time, 0, 5));
        $this->assertSame(240, $record->duration_raw_minutes);
    }

    /** Empat entri @1j50m — satu sesi 7j20m yang bukan kelipatan jam. */
    private function sesiTujuhJamDuaPuluh(): array
    {
        return [
            $this->kimaiSlot(1, self::WEDNESDAY, '18:00', '19:50'),
            $this->kimaiSlot(2, self::WEDNESDAY, '19:50', '21:40'),
            $this->kimaiSlot(3, self::WEDNESDAY, '21:40', '23:30'),
            $this->kimaiSlot(4, self::WEDNESDAY, '23:30', '01:20'),
        ];
    }

    #[Test]
    public function br_04_pembulatan_berlaku_sekali_untuk_seluruh_sesi(): void
    {
        $user = $this->kimaiUser(rounding: true);

        $this->fakeKimai($this->sesiTujuhJamDuaPuluh());
        $this->sync($user);

        $record = OvertimeRecord::firstOrFail();

        // 440 menit dibulatkan SEKALI jadi 420 (7 jam).
        //
        // Angka ini sengaja 420, bukan 480. Sebelum peleburan, empat entri ini jadi
        // empat record dan MASING-MASING dibulatkan: 4 × round(110/60) = 4 × 2 jam =
        // 480 menit, cukup untuk Tier2 dan uang makan Rp 100.000. Itu menggelembungkan
        // hak sampai 40 menit, dan membuat besarnya bergantung pada berapa kali Kimai
        // memecah sesinya — lembur yang sama persis bisa bernilai beda hanya karena
        // pecahannya beda. Jangan "perbaiki" 420 jadi 480 (BR-04).
        $this->assertSame(440, $record->duration_raw_minutes);
        $this->assertSame(420, $record->duration_effective_minutes);
        $this->assertTrue($record->rounding_applied);

        $this->assertSame(Tier::Tier1, $record->tier);
        $this->assertSame(50_000, $record->meal_allowance_amount);
    }

    #[Test]
    public function br_04_tanpa_pembulatan_sesi_gabungan_tidak_digeser(): void
    {
        $user = $this->kimaiUser(rounding: false);

        $this->fakeKimai($this->sesiTujuhJamDuaPuluh());
        $this->sync($user);

        $record = OvertimeRecord::firstOrFail();

        // Data yang sama persis: 420 di test sebelumnya benar-benar milik pembulatan,
        // bukan efek samping peleburan sesi.
        $this->assertSame(440, $record->duration_raw_minutes);
        $this->assertSame(440, $record->duration_effective_minutes);
        $this->assertFalse($record->rounding_applied);
    }
}
