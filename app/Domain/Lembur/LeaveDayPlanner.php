<?php

namespace App\Domain\Lembur;

/**
 * CT-03 — menit cuti menjadi daftar slot jam yang akan ditulis ke Kimai.
 *
 * Menitnya diambil dari `minutes_required` pada klaim, BUKAN dari ClaimType.
 * Itu bukan detail: `minutes_required` berasal dari aturan berversi
 * (OvertimeRule::minutesForFullDay() / minutesForLateArrival(), BR-24), dan
 * admin boleh mengubahnya. Kalau slot dikunci pada bentuk klaim, admin yang
 * menurunkan `tier2_leave_minutes` menjadi 420 akan membuat ledger saldo
 * memotong 7 jam sementara Kimai menerima 8 — selisih satu jam yang tidak
 * muncul di mana pun. Saldo dan timesheet harus menyebut angka yang sama, dan
 * satu-satunya angka yang sudah tersimpan di baris klaim adalah menit itu.
 */
class LeaveDayPlanner
{
    /**
     * Pola blok satu hari kerja. Jam istirahat 12:00–13:00 memang TIDAK ada di
     * daftar ini — justru ketiadaannya yang membuat 09:00–18:00 hanya memuat
     * 480 menit, bukan 540.
     *
     * Konstanta, bukan turunan `work_start_time`/`work_end_time` di OvertimeRule:
     * aturan menyimpan JENDELA kerja, tetapi tidak menyimpan di mana istirahat
     * jatuh maupun di mana batas antar-blok. Menambah kolom baru ke
     * `overtime_rules` untuk nilai yang tidak pernah berubah hanya memindahkan
     * konstanta ini ke tempat yang lebih jauh. Sebagai gantinya RENTANGNYA diuji
     * terhadap aturan baseline, sehingga mengubah jam kerja tanpa menyesuaikan
     * pola ini gagal dengan berisik alih-alih diam-diam meleset.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const BLOK = [
        ['09:00', '11:00'],   // 120
        ['11:00', '12:00'],   //  60  — ditutup jam istirahat
        ['13:00', '14:00'],   //  60
        ['14:00', '16:00'],   // 120
        ['16:00', '18:00'],   // 120
    ];

    /**
     * Slot berurutan yang totalnya sama dengan $minutesRequired.
     *
     * Sisa yang lebih pendek dari bloknya MEMOTONG blok terakhir alih-alih
     * membulatkannya ke atas: membulatkan berarti mengirim ke Kimai lebih banyak
     * daripada yang dipotong dari saldo.
     *
     * @return array<int, LeaveSlot>
     */
    public function slots(int $minutesRequired): array
    {
        $sisa = max(0, $minutesRequired);
        $slots = [];

        foreach (self::BLOK as [$mulai, $selesai]) {
            if ($sisa <= 0) {
                break;
            }

            $blok = LeaveSlot::fromClock($mulai, $selesai);

            if ($sisa >= $blok->durationMinutes()) {
                $slots[] = $blok;
                $sisa -= $blok->durationMinutes();

                continue;
            }

            $slots[] = $blok->endingAt($blok->startMinute + $sisa);
            $sisa = 0;
        }

        return $slots;
    }

    /**
     * Menit yang tidak kebagian jam kerja karena satu hari sudah penuh.
     *
     * Dilaporkan, bukan didiamkan: klaim yang menuntut lebih dari satu hari kerja
     * akan memotong saldo lebih banyak daripada yang pernah sampai ke Kimai, dan
     * user berhak tahu selisihnya.
     */
    public function overflowMinutes(int $minutesRequired): int
    {
        return max(0, $minutesRequired - $this->capacityMinutes());
    }

    /** Kapasitas satu hari kerja menurut pola di atas — 480 menit. */
    public function capacityMinutes(): int
    {
        $total = 0;

        foreach (self::BLOK as [$mulai, $selesai]) {
            $total += LeaveSlot::fromClock($mulai, $selesai)->durationMinutes();
        }

        return $total;
    }
}
