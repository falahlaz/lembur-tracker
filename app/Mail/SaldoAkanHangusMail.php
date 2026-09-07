<?php

namespace App\Mail;

use App\Models\LeaveBalance;
use App\Support\Format;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SaldoAkanHangusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly LeaveBalance $balance,
        public readonly int $daysLeft,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf(
            '%s cuti pengganti kamu hangus %s',
            Format::durasi($this->balance->remainingMinutes()),
            Format::sisaWaktu($this->daysLeft),
        ));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.saldo-akan-hangus');
    }
}
