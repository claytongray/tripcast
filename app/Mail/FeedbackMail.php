<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Internal notification: a user's site feedback (Story 10.1), sent to the team
 * inbox with reply-to set to the sender so a plain inbox reply reaches them.
 * No branding/unsubscribe/promo — an ops email, not a subscription. Queued by
 * the caller. The property is $userMessage, not $message: Laravel injects a
 * reserved $message variable into every mail view.
 */
class FeedbackMail extends Mailable
{
    public function __construct(
        public User $user,
        public string $userMessage,
        public string $source,
        public int $tripCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Feedback from '.$this->user->email,
            replyTo: [new Address($this->user->email)],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.feedback',
            text: 'emails.feedback-text',
            with: [
                'userMessage' => $this->userMessage,
                'email' => $this->user->email,
                'source' => $this->source,
                'tripCount' => $this->tripCount,
            ],
        );
    }
}
