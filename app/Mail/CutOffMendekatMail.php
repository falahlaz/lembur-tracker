<?php

namespace App\Mail;

use App\Models\PayrollPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CutOffMendekatMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PayrollPeriod $period,
        public readonly int $unapprovedCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Cut-off periode {$this->period->label} sudah dekat",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.cut-off-mendekat');
    }
}
