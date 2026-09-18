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
php artisan queue:work        # sync Kimai, upload timesheet, export Excel, email
```

`queue:work` **wajib jalan**, bukan opsional: tombol "Sync Kimai" dan "Kirim …
entri ke Kimai" hanya menaruh job ke antrean lalu langsung kembali. Tanpa worker,
upload berhenti di status "Menunggu" — halaman akan menutupnya sendiri sebagai
gagal setelah `KIMAI_LOCK_TTL` (10 menit) dan menyebutkan worker-nya, tetapi tidak
satu pun entri sampai ke Kimai.

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

Sekali cek setelah `up`, karena kegagalannya tidak kelihatan dari tampilan:

```bash
curl -I http://localhost:8080/livewire/livewire.js                          # harus 200
curl -I http://localhost:8080/js/filament/support/support.js                # harus 200
curl -I http://localhost:8080/js/filament/forms/components/select.js        # harus 200
curl -I http://localhost:8080/js/filament/forms/components/date-time-picker.js  # harus 200
curl -I http://localhost:8080/js/filament/forms/components/file-upload.js   # harus 200
```

Livewire menyajikan JS-nya lewat route, bukan berkas di `public/`. Kalau
nginx menjawab 404 di sini, CSS tetap termuat sehingga halaman *terlihat*
normal, tetapi Livewire dan Alpine tidak pernah jalan: tombol tidak bereaksi,
form login hanya me-reload halaman, dan input password tampil sebagai teks
terang. Jangan menambahkan blok `location` regex `\.(css|js)$` tanpa
`try_files` — itu persis yang menjegal route ini.

Tiga URL berikutnya adalah berkas sungguhan, diterbitkan
`php artisan filament:upgrade` saat image dibangun. Komponen `native(false)`
mengambilnya belakangan lewat `x-load`, jadi kalau salah satunya 404 halaman
tetap ter-style dan tetap render, tetapi setiap **date picker berubah jadi
input readonly yang kalendernya tak pernah terbuka** dan setiap **select jadi
kotak kosong** — tanpa error di console. Build image akan gagal kalau ketiganya
tidak terbit (lihat `Dockerfile`), dan `AssetsPublishedTest` menjaganya di CI.

### Di balik reverse proxy TLS

Container nginx sengaja hanya `listen 80`; HTTPS ditutup di reverse proxy VPS.
Artinya php-fpm **selalu** melihat request http polos, dan satu-satunya petunjuk
skema asli adalah header `X-Forwarded-Proto` dari proxy. `trustProxies()` di
`bootstrap/app.php` yang menerjemahkannya; tanpa itu `Request::isSecure()` false
dan `asset()` menulis `http://` di halaman `https`, lalu browser memblokir modul
Filament sebagai *mixed content* — date picker, select, dan file upload mati
tanpa satu pun error di console, persis seperti kalau berkasnya 404.

Yang perlu diisi di `.env` produksi: `APP_URL` dengan origin https sebenarnya,
dan `SESSION_SECURE_COOKIE=true`. Cek skemanya tanpa perlu menyentuh VPS:

```bash
curl -s -H 'X-Forwarded-Proto: https' http://localhost:8080/app/login \
  | grep -oE 'https?://[^"]*filament[^"]*\.js' | sort -u
```

Semua baris harus `https://`. Kalau di produksi masih `http://`, reverse
proxy-nya tidak mengirim `X-Forwarded-Proto` sama sekali — set `FORCE_HTTPS=true`
sebagai jaring pengaman, lalu `php artisan config:cache`.

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

Upload timesheet ke Kimai hidup di `app/Domain/Timesheet/`:

| Berkas | Aturan |
|---|---|
| `SlotLabel` | UP-01 — label jam kolom A jadi jam sungguhan, termasuk angka 12 yang terbalik |
| `WorkbookReader` | UP-02 — satu-satunya yang menyentuh berkas; .xlsx jadi grid biasa |
| `TimesheetWorkbookParser` | UP-03 — grid jadi calon entri, beserta bentrok di dalam berkas |
| `DuplicateDetector` | UP-04 — slot yang sudah terisi di Kimai atau sudah pernah diupload |
| `UploadDrafter` | UP-05 — pratinjau yang disimpan, bukan ditahan di memori |
| `UploadPoster` | UP-06 — kirim per entri, beserta kebijakan kegagalannya |
| `UploadRecovery` | UP-10 — upload yang macet melewati TTL kunci ditutup sendiri |
| `KimaiCatalog` | UP-07 — daftar project dan activity, dengan cache pendek per user; jatuh ke cermin lokal saat Kimai mati |
| `ActivityResolver` | UP-08 — nama activity di sheet jadi id Kimai |
| `CatalogResult` | UP-09 — sebuah daftar beserta asal-usulnya (Kimai / cermin / kosong) |

Sinkronisasi Kimai hidup terpisah di `app/Domain/Kimai/`:

| Berkas | Aturan |
|---|---|
| `KimaiClient` | §4 — satu-satunya tempat token menyentuh jaringan |
| `KimaiTimesheet` | SY-16 — parse offset lalu konversi ke WIB |
| `SyncRangeResolver` | SY-02–SY-04 — rentang yang ditarik, berbasis watermark |
| `SessionGrouper` | SY-23 — entri mana milik sesi lembur yang mana |
| `OvertimeSession` | SY-23/SY-24 — satu sesi: jam, durasi gabungan, istirahat |
| `SessionWriter` | SY-13–SY-15, SY-25 — sesi → record, beserta seluruh pagarnya |
| `KimaiSynchronizer` | SY-05–SY-07 — rentang, fetch, filter, laporan |
| `KimaiConnection` | F-11 — simpan/tes/hapus API key |
| `KimaiCatalogSync` | LG-01 — tarik seluruh project & activity ke cermin lokal (manual, tanpa scheduler) |
| `KimaiCatalogMirror` | LG-02 — sisi baca cermin, berbentuk sama dengan keluaran KimaiClient |

### Enam hal yang paling mudah salah

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
5. **Durasi record punya satu pintu baca: `OvertimeRecord::rawMinutes()`** (SY-10).
   Kimai mengirim `duration` yang sudah bersih dari `break`, sehingga sengaja
   berbeda dari selisih jam. Observer DAN `OvertimeDayCalculator` sama-sama
   memanggil method itu; menghitung sendiri dari `start_time`/`end_time` di salah
   satunya akan menimpa angka dari Kimai tanpa satu pun pesan error.
6. **Satu entri Kimai ≠ satu lembur** (SY-23). Kimai membatasi satu timesheet
   maksimal 2 jam, jadi lembur 8 jam datang sebagai empat entri. `SessionGrouper`
   satu-satunya yang memutuskan pengelompokannya dan `SessionWriter` satu-satunya
   yang menulisnya — sync harian dan `lemburku:kimai:regroup` sama-sama lewat situ,
   supaya aturan "kapan data user boleh ditimpa" tidak punya dua salinan yang
   berbeda perlahan.

---

## Pengujian

```bash
php artisan test
```

299 test, 1104 assertion. Setiap aturan bisnis punya test bernama sesuai ID-nya:

```bash
php artisan test --filter=br_13         # clamping akhir bulan
php artisan test --filter=br_22         # urutan validasi klaim
php artisan test --filter=sy_            # aturan sinkronisasi Kimai
php artisan test --filter=t_2            # skenario uji wajib PRD Sync Kimai §11
php artisan test --filter=SlotLabel     # pembacaan label jam workbook
php artisan test --filter=Timesheet     # parsing dan upload timesheet
```

Test format workbook memakai seam array-in seperti `HistoricalImporter`, jadi hampir
semuanya jalan tanpa satu pun .xlsx. Yang memang perlu berkas sungguhan
(`WorkbookReaderTest`) **membangun** workbook-nya sendiri dengan PhpSpreadsheet alih-alih
memuat fixture biner — rich text ber-newline dan serial tanggal justru yang perlu
dibuktikan, dan blob yang di-commit tidak bisa direview siapa pun.

Test yang menyentuh saldo **wajib** memanggil `freezeDate()` lebih dulu. Seluruh
sistem digerakkan tanggal; tanpa membekukan waktu, test yang hari ini hijau akan
merah bulan depan hanya karena jam dinding bergerak.

---

## Sinkronisasi Kimai

Jam lembur sudah wajib diisi di KIMAI, jadi tidak ada alasan menyuruh orang
mengetiknya ulang di sini. **Pengaturan → Preferensi → Integrasi Kimai** untuk
memasang API key (buat di Kimai lewat Profil → API Access), lalu tombol **Sync
Kimai** di Dashboard atau Daftar Lembur menariknya di latar belakang.

Yang datang sendiri: tanggal, jam, durasi, deskripsi. Yang tetap manual: URL
evidence dan status approval. Record hasil sync diberi badge **"Lengkapi
evidence"** dan tidak bisa diajukan sebelum deep link Kimai diganti link SPL
atau timesheet di OneDrive — SOP §6 tetap mewajibkan keduanya.

Sebelum dipakai di instance baru, verifikasi kontrak API-nya dulu:

```bash
php artisan lemburku:kimai:probe
```

Command itu read-only dan tidak menyimpan token; ia meminta token lewat prompt
tersembunyi, lalu melaporkan versi Kimai, nama parameter urutan yang diterima
(`order_by` vs `orderBy`), dan apakah `tags[]` benar-benar menyaring. Hasilnya
dituangkan ke `KIMAI_ORDER_PARAM` dan `KIMAI_TAGS` di `.env` — tidak ada kode
yang perlu diubah.

### Satu sesi lembur = satu catatan

Kimai membatasi satu timesheet **maksimal 2 jam**, jadi lembur 4 jam datang sebagai
dua entri dan lembur 8 jam sebagai empat. Yang menentukan entri mana milik sesi yang
sama adalah jendela lemburnya, bukan jumlah entrinya:

| | Jendela | Lewat tengah malam |
|---|---|---|
| **Hari kerja** | 18:00 hari ini → 09:00 besok | ikut digabung ke tanggal mulai |
| **Weekend** | seluruh hari kalender | jadi catatan sendiri di tanggal berikutnya |

Yang menentukan aturan mana yang dipakai adalah **hari saat jendela dibuka**. Jumat
18:00 → Sabtu 03:00 jadi SATU catatan di hari Jumat, karena jendelanya dibuka di hari
kerja. Minggu 20:00 → Senin 02:00 jadi DUA catatan, karena jendelanya dibuka di
weekend.

Jendela hari kerja hanya terbuka kalau ada entri yang **membukanya** — yaitu entri
yang mulai jam 18:00 atau lebih. Lembur Kamis pagi jam 08:00 tetap milik hari Kamis
selama Rabu malam memang tidak ada lembur. Batas 18:00 dan 09:00 sendiri diambil dari
`work_end_time` dan `work_start_time` di **Pengaturan → Aturan Lembur**, jadi bisa
digeser tanpa mengubah kode.

Entri hari kerja yang jatuh di dalam jam kerja (misal Selasa 14:00–16:00) tetap
diimpor, hanya tidak digabung ke sesi mana pun.

Record lembur yang terlanjur masuk dengan skema lama — satu entri Kimai satu catatan —
digabung dengan:

```bash
php artisan lemburku:kimai:regroup --dry-run   # lihat rencananya dulu
php artisan lemburku:kimai:regroup
```

Command itu bekerja murni dari database, tidak menghubungi Kimai, dan boleh dijalankan
berkali-kali. Catatan yang sudah kamu edit, yang sudah diajukan, atau yang saldonya
sudah dipakai klaim tidak akan disentuh — ketiganya dilaporkan beserta alasannya.

### Lima hal yang mudah mengagetkan

- **Durasi bisa lebih pendek dari selisih jam.** 19:00–23:00 dengan istirahat 30
  menit tercatat 3 jam 30 menit, karena `duration` dari Kimai sudah bersih dari
  `break` (SY-10). Istirahatnya ditampilkan supaya selisihnya bisa dijelaskan.
- **Jeda antar entri juga muncul sebagai istirahat.** Entri 18:00–20:00 dan
  21:00–23:00 jadi satu catatan 18:00–23:00 berdurasi 4 jam, dengan istirahat 1 jam
  (SY-24). Jam kosong di tengah tidak dihitung sebagai lembur.
- **Kalau pembulatan nyala, jamnya bisa turun dibanding sebelum peleburan.** Empat
  entri @1j50m dulu dibulatkan satu-satu jadi 4×2 jam = 8 jam; sekarang satu sesi
  7j20m dibulatkan sekali jadi 7 jam. Yang sekarang yang benar — yang lama membuat
  hak lembur bergantung pada berapa kali Kimai memecah sesinya (BR-04).
- **Record yang kamu edit tidak pernah ditimpa lagi.** Sekali disentuh manusia,
  record itu keluar dari jangkauan sync selamanya (SY-14) — dan pada catatan
  gabungan itu membekukan seluruh sesinya, termasuk entri yang baru kamu tambahkan
  di Kimai setelahnya.
- **Menghapus entri di Kimai tidak menghapus lemburnya di sini.** Entri yang hilang
  dari respons belum tentu terhapus: bisa jadi timernya dijalankan lagi, atau tagnya
  dilepas. Menebak salah terlalu mahal, jadi sync tidak pernah membuang anggota sesi
  yang sudah tersimpan — hapus catatannya sendiri kalau memang tidak berlaku.

---

## Upload timesheet ke Kimai

Jam kerja diisi di workbook **`Timesheet <bulan>.xlsx`** (sheet `Daily` + `Overtime`),
lalu harus masuk ke Kimai satu per satu. **Pencatatan → Upload Timesheet** mengerjakannya:
unggah berkasnya, periksa pratinjaunya, lalu kirim di latar belakang. Entri masuk sebagai
pemilik API key yang dipakai, jadi ini pekerjaan masing-masing orang — bukan admin.

Entri sheet `Overtime` dikirim bertag `Overtime`, sehingga sync menariknya kembali jadi
catatan lembur dengan sendirinya. Entri `Daily` tidak bertag dan berhenti di Kimai. Tidak
ada jalur kedua yang menulis catatan lembur: Kimai tetap satu-satunya sumber kebenaran.

### Project dan activity dipilih lewat nama

Angka id tidak bisa diverifikasi mata, dan project Kimai berganti tiap tahun — salah satu
digit berarti satu periode masuk ke project orang lain. Karena itu:

- **Project** dipilih dari dropdown berisi nama, ditarik dari `/api/projects`. Baris
  `Project ID` di workbook tetap dibaca dan jadi pilihan awalnya.
- **Activity** ditulis sebagai nama di dalam sel:

  ```
  Activity: 31_DEV_FEATURE

  Sprint 8 - MTA-1867
  1. Review AI generated code
  ```

  Namanya diterjemahkan jadi id terhadap **project yang dipilih**, lewat `/api/activities`.
  Activity global (yang tidak terikat project) ikut terjangkau — ia diambil lewat permintaan
  terpisah lalu digabung, karena apakah filter `project=` sudah memuatnya berbeda antar versi
  Kimai.

Pencocokan namanya memaafkan besar-kecil huruf dan pemisah kata, jadi `31_DEV_FEATURE` dan
`31 dev feature` dianggap sama. Nama yang cocok ke lebih dari satu activity **ditolak**, bukan
ditebak. Nama yang tidak ketemu menandai barisnya sendiri beserta letak selnya; sisa berkas
tetap bisa dikirim.

Format lama **`Activity ID: 8` tetap diterima**, jadi workbook periode sebelumnya masih bisa
diunggah ulang. Tombol **Unduh template** di halaman upload menghasilkan contoh dalam format
yang berlaku sekarang.

Sebelum dipakai di instance baru, `php artisan lemburku:kimai:probe` sekarang ikut melaporkan
jumlah project dan activity yang terlihat, dan apakah activity global perlu diambil terpisah.

### Legenda activity, dan tombol sync milik admin

Nama activity harus persis, dan satu-satunya cara memastikannya dulu adalah membuka Kimai
di tab lain setiap kali mengisi workbook. **Pencatatan → Legenda Activity** memindahkan
daftar itu ke tempat orang sedang bekerja:

- Tabel seluruh activity beserta project-nya, bisa dicari dan difilter. Tombol salin
  memberikan **baris siap tempel** `Activity: 31_DEV_FEATURE`, bukan namanya saja — itulah
  bentuk yang dibaca parser. Teks yang sama juga ditampilkan biasa, karena `navigator.clipboard`
  tidak ada di halaman non-HTTPS.
- Memilih filter project **ikut menampilkan activity global**, karena itulah yang benar-benar
  boleh ditulis untuk project tersebut.
- Referensi statis di bawahnya: bentuk sel yang benar, aturan pencocokan nama, daftar label jam
  beserta jam sungguhannya (termasuk konvensi jam 12 yang terbalik), dan daftar project beserta
  `Project ID`-nya.

Halaman ini **terbuka untuk semua user aktif**, termasuk yang belum memasang API key — justru
merekalah yang paling membutuhkannya. Datanya tidak berasal dari token siapa pun, melainkan
dari **cermin lokal** di tabel `kimai_projects` dan `kimai_activities`.

Cermin itu diisi **admin lewat satu tombol**, `Sync Katalog Kimai` di header halaman yang sama.
Sengaja **tanpa scheduler**: project dan activity berganti hitungan bulan sekali, jadi
menjalankannya tiap hari hanya membuang permintaan demi jawaban yang hampir selalu "tidak ada
perubahan". Tombolnya berjalan sinkron — tiga sampai lima GET, selesai dalam hitungan detik —
dan notifikasinya menyebut apa yang berubah, bukan sekadar "berhasil". Padanannya di baris
perintah, untuk menyemai deploy baru tanpa login:

```sh
php artisan lemburku:kimai:catalog          # pakai admin pertama yang punya token
php artisan lemburku:kimai:catalog --user=3
```

Cerminnya **read-only**: hanya sync katalog yang menulisnya, tidak pernah disunting manusia,
dan boleh dihapus lalu diisi ulang kapan saja. Kimai tetap satu-satunya sumber kebenaran.

Efek sampingnya: saat Kimai **tidak terjangkau**, halaman Upload Timesheet tidak lagi memaksa
orang mengetik id project dengan tangan. Dropdown project dan pencocokan nama activity jatuh ke
cermin, dengan peringatan jelas yang menyebut kapan terakhir disinkronkan — activity yang dibuat
setelah itu memang belum ada di sana. Yang tidak ikut jatuh ke cermin adalah pemeriksaan
duplikat dan pengirimannya sendiri: keduanya tetap menuntut Kimai hidup, dan cermin tidak boleh
membuat unggahan terlihat lebih pasti daripada kenyataannya.

### Jam dibaca dari label, bukan dari nomor baris

Sheet `Daily` punya 5 baris slot, sheet `Overtime` punya 12, dan keduanya pernah bergeser.
Karena itu yang dibaca adalah **label di kolom A**, bukan posisi barisnya.

Template membalik konvensi jam 12 — `12 AM` berarti tengah hari dan `12 PM` berarti tengah
malam:

| Label | Dibaca jadi |
|---|---|
| `10 AM - 12 AM` | 10:00–12:00 (siang) |
| `10 PM - 12 PM` | 22:00–24:00 (tengah malam hari berikutnya) |

Rentang yang benar-benar melewati tengah malam (`11 PM - 1 AM`) **ditolak**, bukan ditebak.
Label yang tidak dikenali juga dilaporkan di pratinjau — supaya template yang berubah
ketahuan, bukan menghilangkan sebaris entri tanpa suara.

### Tiga hal yang mudah mengagetkan

- **Mengirim ulang berkas yang sama tidak menghasilkan duplikat.** Sebelum mengirim, rentang
  tanggalnya ditarik dari Kimai **tanpa filter tag** — entri `Daily` yang tidak bertag tetap
  menempati jamnya — dan slot yang sudah terisi ditandai dilewati. Lapis keduanya murni
  database, jadi tetap bekerja saat Kimai tidak terjangkau. Kimai sendiri tidak punya
  idempotency key; tanpa dua lapis ini, satu klik berlebih berarti menghapus puluhan entri
  satu per satu.
- **Upload yang berhenti di tengah aman dilanjutkan.** Status disimpan per entri, dan yang
  sudah terkirim secara struktural tidak bisa terambil lagi. Karena itu job-nya sengaja
  `tries = 1`: retry otomatis atas POST yang tidak idempoten justru pabrik duplikat.
- **Satu entri ditolak tidak menjatuhkan sisanya.** 400 dari Kimai menandai entri itu gagal
  beserta alasan aslinya lalu lanjut; hanya token ditolak (401) dan instance mati (5xx) yang
  menghentikan seluruh upload.

Sheet Daily satu periode penuh mestinya sekitar 8 jam × hari kerja — angka per sheet di
pratinjau ada supaya itu bisa dicek sekilas sebelum mengirim.

---

## Yang belum dikerjakan

- **Fase 2** — peran Atasan/PM (kolom `manager_id` dan sistem role sudah disiapkan,
  jadi tidak perlu migrasi struktural), approval di dalam sistem, dashboard tim.
- **Fase 3** — sinkronisasi status ESS. Integrasi API KIMAI sudah jalan dua arah: sync
  manual menarik, Upload Timesheet mendorong. Sync otomatis harian (F-14/SY-21) belum.
  Sync **katalog** otomatis bukan pekerjaan yang tertunda melainkan keputusan: project dan
  activity terlalu jarang berubah untuk pantas dijadwalkan.
- **Rentang sync setelah upload periode lama.** `SyncRangeResolver` membatasi jendela sync
  pada periode payroll berjalan, jadi mengunggah workbook bulan lalu memasukkan entrinya ke
  Kimai tetapi TIDAK otomatis menjadikannya catatan lembur di sini. Untuk periode berjalan
  — kasus normalnya — tidak ada masalah.
- **OQ-1** — tabel hari libur nasional. Sengaja dilewati: BR-08 membuat hari libur
  dan hari kerja diperlakukan sama, jadi dampaknya kosmetik saja.
