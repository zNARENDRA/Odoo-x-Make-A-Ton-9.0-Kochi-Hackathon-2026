<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BillingUpdate extends Mailable
{
    /** @param array<string, string> $details */
    public function __construct(
        public string $title,
        public string $greeting,
        public string $intro,
        public array $details,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.billing-update');
    }
}
