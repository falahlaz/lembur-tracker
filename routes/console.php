<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Jadwal LemburKu
|--------------------------------------------------------------------------
|
| Semua jadwal memakai kalender WIB (NFR §10) meskipun timestamp disimpan UTC.
|
| §9.3 menyebut satu job harian pukul 00:05, sementara F-07 mensyaratkan
| reminder terkirim pukul 08:00. Keduanya dipenuhi dengan memisahkan tugasnya:
| pemeliharaan saldo jalan tengah malam (agar status hari itu sudah benar saat
| orang membuka aplikasi), pengiriman email menyusul pagi harinya (supaya tidak
| mendarat di kotak masuk jam 12 malam).
|
*/

Schedule::command('lemburku:maintenance')
    ->dailyAt('00:05')
    ->timezone(config('app.display_timezone'))
    ->withoutOverlapping();

Schedule::command('lemburku:reminders --jenis=saldo')
    ->dailyAt('08:00')
    ->timezone(config('app.display_timezone'))
    ->withoutOverlapping();

Schedule::command('lemburku:reminders --jenis=cutoff')
    ->monthlyOn(17, '08:00')
    ->timezone(config('app.display_timezone'));

Schedule::command('lemburku:reminders --jenis=ringkasan')
    ->monthlyOn(19, '08:00')
    ->timezone(config('app.display_timezone'));
