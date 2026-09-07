<?php

namespace App\Mail;

use App\Domain\Lembur\MealAllowanceEstimate;
use App\Models\PayrollPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RingkasanPeriodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PayrollPeriod $period,
        public readonly MealAllowanceEstimate $estimate,
        public readonly int $leaveMinutesEarned,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Rekap lembur periode {$this->period->label}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.ringkasan-periode');
    }
}
