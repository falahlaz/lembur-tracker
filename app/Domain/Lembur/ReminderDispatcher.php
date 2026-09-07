<?php

namespace App\Domain\Lembur;

use App\Enums\BalanceStatus;
use App\Mail\CutOffMendekatMail;
use App\Mail\RingkasanPeriodeMail;
use App\Mail\SaldoAkanHangusMail;
use App\Models\LeaveBalance;
use App\Models\NotificationLog;
use App\Models\OvertimeRecord;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * F-07 — pengiriman reminder.
 *
 * Dua jaminan yang harus dipegang: setiap reminder dapat dimatikan per user,
 * dan setiap pengiriman tercatat di `notification_logs` sehingga tidak pernah
 * terkirim dua kali. Scheduler yang jalan dua kali karena deploy atau retry
 * tidak boleh berubah jadi dua email di kotak masuk orang.
 */
class ReminderDispatcher
{
    public function __construct(
        private readonly PayrollPeriodResolver $periods,
        private readonly MealAllowanceEstimator $estimator,
    ) {}

    /** H-7 dan H-2 sebelum tanggal hangus. */
    public function sendExpiryReminders(): int
    {
        $sent = 0;

        foreach ([7 => NotificationLog::EXPIRY_H7, 2 => NotificationLog::EXPIRY_H2] as $days => $type) {
            $target = today()->addDays($days)->toDateString();

            $balances = LeaveBalance::query()
                ->with('user')
                ->whereIn('status', [BalanceStatus::Active->value, BalanceStatus::PartiallyUsed->value])
                ->whereDate('expires_at', $target)
                ->get()
                ->filter(fn (LeaveBalance $b) => $b->remainingMinutes() > 0);

            foreach ($balances as $balance) {
                if ($this->deliver($balance->user, $type, $balance->id, new SaldoAkanHangusMail($balance, $days))) {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /** Tanggal 17 — mengingatkan lembur yang belum diajukan sebelum cut-off. */
    public function sendCutoffReminders(): int
    {
        $period = $this->periods->current();
        $sent = 0;

        foreach ($this->activeUsers() as $user) {
            $unapproved = OvertimeRecord::query()
                ->where('user_id', $user->id)
                ->whereBetween('overtime_date', [
                    $period->period_start->toDateString(),
                    $period->period_end->toDateString(),
                ])
                ->whereNotIn('status', ['approved', 'rejected'])
                ->count();

            // Tidak ada gunanya mengirim email "kamu tidak perlu melakukan apa-apa".
            if ($unapproved === 0) {
                continue;
            }

            if ($this->deliver($user, NotificationLog::CUTOFF_APPROACHING, $period->id, new CutOffMendekatMail($period, $unapproved))) {
                $sent++;
            }
        }

        return $sent;
    }

    /** Tanggal 19 — rekap periode yang baru saja ditutup. */
    public function sendPeriodSummaries(): int
    {
        // Pada tanggal 19 periode berjalan baru saja berganti; yang diringkas
        // adalah periode SEBELUMNYA, yang ditutup kemarin.
        $closed = $this->periods->resolve(today()->subDays(2));
        $sent = 0;

        foreach ($this->activeUsers() as $user) {
            $estimate = $this->estimator->forPeriod($user, $closed);

            if ($estimate->recordCount === 0) {
                continue;
            }

            $leaveMinutes = (int) OvertimeRecord::query()
                ->where('user_id', $user->id)
                ->whereBetween('overtime_date', [
                    $closed->period_start->toDateString(),
                    $closed->period_end->toDateString(),
                ])
                ->sum('leave_credit_minutes');

            if ($this->deliver($user, NotificationLog::PERIOD_SUMMARY, $closed->id, new RingkasanPeriodeMail($closed, $estimate, $leaveMinutes))) {
                $sent++;
            }
        }

        return $sent;
    }

    /** Satu pintu keluar: cek preferensi, cek duplikat, kirim, catat. */
    public function deliver(User $user, string $type, ?int $relatedId, object $mailable): bool
    {
        if (! $user->is_active || ! $user->wantsNotification($type)) {
            return false;
        }

        if (NotificationLog::alreadySent($user->id, $type, $relatedId)) {
            return false;
        }

        Mail::to($user->email)->send($mailable);

        NotificationLog::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'related_id' => $relatedId,
            'channel' => 'mail',
            'sent_at' => now(),
        ]);

        return true;
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function activeUsers()
    {
        return User::query()->where('is_active', true)->get();
    }
}
