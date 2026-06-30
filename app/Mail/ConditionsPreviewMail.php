<?php

namespace App\Mail;

use App\Digest\WeatherEmoji;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Dev-only reference email: every WeatherAPI condition rendered with the emoji
 * {@see WeatherEmoji} maps it to, so the icon coverage can be
 * eyeballed in one place (Mailtrap). Never part of the production pipeline.
 */
class ConditionsPreviewMail extends Mailable
{
    /**
     * @param  list<array{code: int, text: string, emoji: string}>  $conditions
     */
    public function __construct(public array $conditions) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Weather condition icons — '.count($this->conditions).' conditions');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.conditions-preview',
            with: ['conditions' => $this->conditions],
        );
    }
}
