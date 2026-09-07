<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GeneralMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public object $user,
        public $subject,
        public string $content,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) $this->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.general',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
