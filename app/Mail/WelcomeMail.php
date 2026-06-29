<?php

namespace App\Mail;

use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * The one-time welcome email (FR-9, AD-11, UX-DR7): calm, no CTA, plain-text
 * twin. Fired once at trip creation, independent of the Forecast Window.
 */
class WelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Trip $trip) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "We're watching ".$this->placeShort(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome',
            text: 'emails.welcome-text',
            with: [
                'place' => $this->trip->canonical_place_name,
                'placeShort' => $this->placeShort(),
                'dateRange' => $this->dateRange(),
                'firstDigestDate' => $this->firstDigestDate()->format('j F'),
            ],
        );
    }

    /**
     * City portion of the canonical name (text before the first comma).
     */
    private function placeShort(): string
    {
        return Str::of($this->trip->canonical_place_name)->before(',')->trim()->value();
    }

    /**
     * Friendly trip date range: "14–21 July" (same month) or "14 July – 2 August".
     */
    private function dateRange(): string
    {
        $departure = $this->trip->departure_date;
        $return = $this->trip->return_date;

        if ($departure->isSameMonth($return)) {
            return $departure->format('j').'–'.$return->format('j F');
        }

        return $departure->format('j F').' – '.$return->format('j F');
    }

    /**
     * When daily digests begin: the Forecast-Window-open date (departure − 7
     * days), floored to today (America/New_York) for trips created in-window.
     * The authoritative cadence predicate is Story 2.2 (AD-11).
     */
    private function firstDigestDate(): CarbonImmutable
    {
        $windowOpen = $this->trip->departure_date->toImmutable()->subDays(7);
        $today = CarbonImmutable::now('America/New_York')->startOfDay();

        return $windowOpen->lessThan($today) ? $today : $windowOpen;
    }
}
