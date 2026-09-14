<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Instance Kimai
    |--------------------------------------------------------------------------
    |
    | PRD Sync Kimai §7 — URL instance adalah konfigurasi APLIKASI, bukan field
    | per user. Seluruh tim memakai instance yang sama, dan menjadikannya milik
    | masing-masing user hanya menambah permukaan kesalahan.
    |
    */

    'base_url' => env('KIMAI_BASE_URL', 'https://timesheet.codeoffice.net'),

    /*
    |--------------------------------------------------------------------------
    | Tag yang ditarik
    |--------------------------------------------------------------------------
    |
    | OQ-3 — kalau suatu saat tim memakai `OT` atau `Lembur`, daftar ini yang
    | diubah, bukan kodenya. Pencocokan di sisi aplikasi case-insensitive (SY-05).
    |
    */

    'tags' => array_filter(array_map('trim', explode(',', (string) env('KIMAI_TAGS', 'Overtime')))),

    /*
    |--------------------------------------------------------------------------
    | Kontrak API
    |--------------------------------------------------------------------------
    |
    | SR-1 — dokumentasi instance tidak dapat diakses dari luar, dan nama
    | parameter urutan berbeda antar versi Kimai. Keduanya dibuat konfigurabel
    | supaya hasil `lemburku:kimai:probe` cukup mengubah nilai di sini.
    |
    */

    'order_param' => env('KIMAI_ORDER_PARAM', 'order_by'),
    'page_size' => (int) env('KIMAI_PAGE_SIZE', 100),
    'max_pages' => (int) env('KIMAI_MAX_PAGES', 50),
    'timeout' => (int) env('KIMAI_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | Rentang sync
    |--------------------------------------------------------------------------
    |
    | Pagar keras kedua di atas batas periode payroll (SY-04). Periode payroll
    | yang biasanya lebih pendek jadi penentu; nilai ini melindungi dari periode
    | yang tidak wajar panjangnya.
    |
    */

    'lookback_days' => (int) env('KIMAI_LOOKBACK_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Kunci job
    |--------------------------------------------------------------------------
    |
    | SY-18 — satu job aktif per user. §10 — job yang macet > 10 menit melepas
    | kuncinya sendiri supaya tombol sync hidup kembali.
    |
    */

    'lock_ttl' => (int) env('KIMAI_LOCK_TTL', 600),

    /*
    |--------------------------------------------------------------------------
    | Zona waktu
    |--------------------------------------------------------------------------
    |
    | SY-16 — parameter dikirim sebagai waktu lokal TANPA offset karena Kimai
    | menafsirkannya dalam timezone user; respons dibaca BESERTA offsetnya lalu
    | dikonversi ke sini.
    |
    */

    'timezone' => env('KIMAI_TIMEZONE', 'Asia/Jakarta'),

];
