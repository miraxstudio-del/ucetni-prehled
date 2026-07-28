<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\Pdf\InvoicePdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvoiceMail extends Mailable
{
    public function __construct(
        public readonly Invoice $invoice,
        private readonly string $subjectLine,
        public readonly ?string $customMessage,
        private readonly InvoicePdf $pdf,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invoice');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => $this->pdf->render($this->invoice)->output(),
                $this->pdf->filename($this->invoice),
            )->withMime('application/pdf'),
        ];
    }
}
