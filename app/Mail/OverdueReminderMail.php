<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OverdueReminderMail extends Mailable
{
    public function __construct(
        public readonly Invoice $invoice,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Připomínka: faktura %s po splatnosti — %s',
                $this->invoice->number,
                $this->invoice->organization->name,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.overdue-reminder');
    }
}
