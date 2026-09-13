# LemburKu

Pencatatan lembur dan cuti pengganti untuk project MyTelkomsel Native — menghitung
uang makan, melacak saldo cuti pengganti, dan memperingatkan **sebelum** saldo hangus.

Dibangun dari spesifikasi **"Spesifikasi LemburKu" v1.0 (7 Sep 2026)** — PRD §1–13 dan
Design Brief §1–10.

> **Ini bukan sistem payroll.** Angka rupiah adalah estimasi berdasarkan catatan
> pribadi, bukan perhitungan gaji yang mengikat. Karyawan tetap wajib mengisi
> SPL, KIMAI, dan ESS; sistem ini catatan paralel untuk kepentingan sendiri.

---

## Menjalankan secara lokal

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

Buka `http://127.0.0.1:8000/app`.

| Akun | Email | Password |
|---|---|---|
| Admin | `admin@lemburku.test` | `password` |
| Karyawan | `falah@lemburku.test` | `password` |
| Karyawan (pembulatan nyala) | `rekan@lemburku.test` | `password` |

Dua akun karyawan sengaja berbeda preferensi pembulatan, supaya risiko **R-2**
(durasi sama, hak berbeda) bisa dilihat langsung.

### Menjalankan jadwal dan antrean

```bash
php artisan schedule:work     # pemeliharaan saldo 00:05 WIB, reminder 08:00 WIB
php artisan queue:work        # export Excel dan pengiriman email
```

Perintah manual:

```bash
php artisan lemburku:maintenance                  # §9.3 — tandai hangus, tuntaskan klaim
php artisan lemburku:reminders --jenis=saldo      # H-7 dan H-2
php artisan lemburku:reminders --jenis=cutoff     # tanggal 17
php artisan lemburku:reminders --jenis=ringkasan  # tanggal 19
```

Email di dev memakai driver `log` (`storage/logs/laravel.log`). Untuk melihatnya
sebagai email sungguhan, jalankan Mailpit dan set `MAIL_MAILER=smtp`,
`MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`.

---

## Menjalankan dengan Docker

`.env` tinggal di host dan dibaca container lewat `env_file` — berkasnya
sengaja **tidak** ikut masuk image (lihat `.dockerignore`). Karena itu semua
penyetelan, termasuk `APP_KEY`, dikerjakan di host sebelum `up`.

```bash
cp .env.example .env
```

Sunting `.env`:

```ini
APP_URL=http://localhost:8080
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=lemburku
DB_USERNAME=lemburku
DB_PASSWORD=secret
REDIS_HOST=redis
REDIS_CLIENT=predis
```

`REDIS_CLIENT=predis` bukan pilihan gaya: image runtime tidak memasang ekstensi
`phpredis`, sedangkan `predis/predis` sudah ikut di `composer.json`.

Isi `APP_KEY` — inilah yang dilakukan `artisan key:generate`, hanya saja
perintah itu menulis ke berkas `.env`, jadi harus dijalankan di host:

```bash
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env
```

Tanpa `openssl` di host, minta container yang menghitungkan — `--show` hanya
mencetak kunci, tidak menulis berkas — lalu salin hasilnya ke `.env`:

```bash
docker compose run --rm --no-deps app php artisan key:generate --show
```

Menjalankan `docker compose exec app php artisan key:generate` tanpa `--show`
akan gagal dengan `file_get_contents(/var/www/html/.env): No such file or
directory`, dan itu memang seharusnya: kunci yang ditulis di dalam container
ikut hilang begitu container diganti.

```bash
docker compose up -d --build
docker compose exec app php artisan migrate --seed
```

Aplikasi ada di `http://localhost:8080/app` (ubah lewat `APP_PORT`).

`DB_USERNAME`, `DB_PASSWORD`, dan `DB_DATABASE` hanya dipakai MySQL saat volume
dibuat pertama kali. Mengubahnya setelah itu tidak berpengaruh sampai
`docker compose down -v` — dan itu menghapus seluruh data.

Stack: nginx · php-fpm · mysql 8.4 · redis · queue worker · scheduler.
Backup harian: pasang `docker/backup.sh` di crontab host (retensi 30 hari).

---

## Arsitektur

Seluruh aturan bisnis hidup di `app/Domain/Lembur/`, bukan di Filament Resource.
Filament hanya lapisan presentasi. Inilah yang membuat BR-01 s/d BR-24 bisa diuji
tanpa merender satu pun halaman.

| Berkas | Aturan |
|---|---|
| `DurationCalculator` | BR-01, BR-03, BR-04 — durasi, lintas tengah malam, pembulatan |
| `OvertimeDayCalculator` | BR-02, BR-05–BR-08 — hitung ulang satu tanggal penuh |
| `BalanceReconciler` | BR-23 — void batch + tandai klaim perlu ditinjau |
| `ExpiryCalculator` | BR-13 — masa berlaku, clamping akhir bulan |
| `PayrollPeriodResolver` | BR-09–BR-11 — cut-off tanggal 19 |
| `LeaveAllocator` | BR-14–BR-16, BR-20 — FIFO, hold/release/settle |
| `ClaimValidator` | BR-19, BR-21, BR-22 — urutan validasi tetap |
| `RuleResolver` | BR-24 — versi aturan per tanggal lembur |
| `MealAllowanceEstimator` | BR-10 — estimasi maksimal vs sudah pasti |
| `BalanceMaintenance` | §9.3 — job harian |
| `ReminderDispatcher` | F-07 — reminder, anti-kirim-ganda |
| `HistoricalImporter` | OQ-4 — impor lembur historis |

### Empat hal yang paling mudah salah

1. **Hak melekat pada TANGGAL, bukan record** (BR-02 + BR-07). Menyimpan, mengubah,
   atau menghapus satu record memicu hitung ulang seluruh tanggal itu.
   `OvertimeDayCalculator::recalculate()` adalah satu-satunya pintu masuk.
2. **Saldo yang sudah dipakai tidak boleh berubah diam-diam** (BR-23). Batch di-`void`,
   klaim ditandai `needs_review`, user dinotifikasi — klaim tidak pernah dibatalkan
   otomatis.
3. **Tanggal itu tanggal, bukan timestamp.** `overtime_date`, `claim_date`, `expires_at`
   dan `earned_date` bertipe `date` dan tidak pernah dikonversi timezone. Mencampurnya
   dengan konversi UTC↔WIB membuat saldo hangus sehari lebih cepat.
4. **Rupiah selalu integer.** Tidak ada float di mana pun.

---

## Pengujian

```bash
php artisan test
```

122 test, 493 assertion. Setiap aturan bisnis punya test bernama sesuai ID-nya:

```bash
php artisan test --filter=br_13     # clamping akhir bulan
php artisan test --filter=br_22     # urutan validasi klaim
```

Test yang menyentuh saldo **wajib** memanggil `freezeDate()` lebih dulu. Seluruh
sistem digerakkan tanggal; tanpa membekukan waktu, test yang hari ini hijau akan
merah bulan depan hanya karena jam dinding bergerak.

---

## Yang belum dikerjakan

- **Fase 2** — peran Atasan/PM (kolom `manager_id` dan sistem role sudah disiapkan,
  jadi tidak perlu migrasi struktural), approval di dalam sistem, dashboard tim.
- **Fase 3** — import CSV KIMAI, integrasi API KIMAI, sinkronisasi status ESS.
- **OQ-1** — tabel hari libur nasional. Sengaja dilewati: BR-08 membuat hari libur
  dan hari kerja diperlakukan sama, jadi dampaknya kosmetik saja.
