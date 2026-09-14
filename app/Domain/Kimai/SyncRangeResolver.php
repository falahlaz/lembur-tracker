<?php

namespace App\Domain\Kimai;

use App\Domain\Lembur\PayrollPeriodResolver;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Menentukan rentang yang ditarik setiap kali job berjalan.
 *
 * Menggantikan SY-02. PRD menghitung batas bawah dari tanggal lembur TERAKHIR
 * milik user, tetapi itu pecah begitu record manual ikut bermain: user mencatat
 * lembur hari ini lewat form, jendela sync melompat ke hari ini, dan entri Kimai
 * yang lebih lama tidak akan pernah tertarik. Karena itu batas bawah memakai
 * watermark `users.kimai_synced_through` yang hanya bergerak setelah run sukses.
 *
 * Batas mundur memakai awal periode payroll berjalan, bukan 90 hari (SY-04):
 * BR-13 membuat saldo hangus setelah satu bulan, jadi menarik lembur tiga bulan
 * lalu hanya memproduksi saldo yang sudah mati saat lahir. 90 hari tetap ada
 * sebagai pagar keras kedua lewat config.
 */
class SyncRangeResolver
{
    public function __construct(private readonly PayrollPeriodResolver $periods) {}

    public function for(User $user, ?CarbonImmutable $now = null): SyncRange
    {
        $now = $now ?? CarbonImmutable::now(config('kimai.timezone'));

        $floor = $this->floor($user, $now);

        // Watermark dimulai dari tanggalnya sendiri, bukan sesudahnya, supaya
        // timesheet lain di tanggal yang sama — yang mungkin diinput belakangan
        // di Kimai — ikut tertangkap (semangat SY-02).
        $begin = $user->kimai_synced_through !== null
            ? CarbonImmutable::parse($user->kimai_synced_through)->setTimezone(config('kimai.timezone'))->startOfDay()
            : $floor;

        if ($begin->lt($floor)) {
            $begin = $floor;
        }

        // Sync pertama kali (SY-03) jatuh ke cabang yang sama: tanpa watermark,
        // batas bawahnya adalah awal periode payroll berjalan, sehingga estimasi
        // uang makan periode ini langsung lengkap.
        return new SyncRange(begin: $begin, end: $now);
    }

    /** Batas mundur maksimum: yang paling belakangan di antara dua pagar. */
    private function floor(User $user, CarbonImmutable $now): CarbonImmutable
    {
        $periodStart = CarbonImmutable::parse($this->periods->boundsFor($now)['start'])
            ->setTimezone(config('kimai.timezone'))
            ->startOfDay();

        $hardFloor = $now->subDays((int) config('kimai.lookback_days'))->startOfDay();

        return $periodStart->gt($hardFloor) ? $periodStart : $hardFloor;
    }
}
