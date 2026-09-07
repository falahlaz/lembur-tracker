<?php

namespace App\Mail;

use App\Models\LeaveBalance;
use App\Models\LeaveClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** BR-23 — user harus tahu klaim mana yang perlu diperbaiki, dan kenapa. */
class KlaimPerluDitinjauMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly LeaveClaim $claim,
        public readonly LeaveBalance $balance,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ada klaim cuti pengganti yang perlu kamu tinjau');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.klaim-perlu-ditinjau');
    }
}
