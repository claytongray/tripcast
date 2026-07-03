<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Internal notification: a user's site feedback (Story 10.1), sent to the team
 * inbox with reply-to set to the sender so a plain inbox reply reaches them.
 * No branding/unsubscribe/promo — an ops email, not a subscription. Queued by
 * the caller. Scalars only, deliberately: a User property would serialize the
 * whole row (password hash included) into the queue payload — the serialized
 * PHP-object-at-rest category docs/deployment.md bans — and the feedback
 * should still arrive even if the account is deleted before the worker runs.
 * The property is $userMessage, not $message: Laravel injects a reserved
 * $message variable into every mail view.
 */
class FeedbackMail extends Mailable
{
    public function __construct(
        public string $senderEmail,
        public string $userMessage,
        public string $source,
        public int $tripCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Feedback from '.$this->senderEmail,
            replyTo: [new Address($this->senderEmail)],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.feedback',
            text: 'emails.feedback-text',
            with: [
                'userMessage' => $this->userMessage,
                'email' => $this->senderEmail,
                'source' => $this->source,
                'tripCount' => $this->tripCount,
            ],
        );
    }
}
