<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The passwordless sign-in email (AD-6, UX-DR10): one accent button, calm copy,
 * plain-text twin. Sent synchronously so the link is immediate.
 */
class MagicLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $url,
        public int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your tripcast sign-in link',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.magic-link',
            text: 'emails.magic-link-text',
            with: [
                'url' => $this->url,
                'ttlMinutes' => $this->ttlMinutes,
            ],
        );
    }
}
