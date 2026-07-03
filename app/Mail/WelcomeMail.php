<?php

namespace App\Mail;

use App\Digest\CadencePredicate;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
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
            subject: "You're all set for ".$this->placeShort(),
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
                'firstDigestDate' => $this->firstForecastDate()->format('F j, Y'),
                'postalAddress' => config('tripcast.postal_address'),
                // Signed "see a sample now" CTA (out-of-window nurture): permanent
                // signature, scoped to this confirmed user. Reuses the generic sample.
                'sampleUrl' => URL::signedRoute('email.sample.send', ['user' => $this->trip->user->id]),
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
     * Friendly, readable trip date range: "July 14–21, 2026" (same month),
     * "July 14 – August 2, 2026" (same year), or "December 30, 2026 –
     * January 3, 2027" (crossing the year). Month-first with the year for
     * scannability (UX copy pass).
     */
    private function dateRange(): string
    {
        $departure = $this->trip->departure_date;
        $return = $this->trip->return_date;

        if ($departure->isSameMonth($return)) {
            return $departure->format('F j').'–'.$return->format('j, Y');
        }

        if ($departure->isSameYear($return)) {
            return $departure->format('F j').' – '.$return->format('F j, Y');
        }

        return $departure->format('F j, Y').' – '.$return->format('F j, Y');
    }

    /**
     * When daily forecasts begin — delegated to the single cadence authority
     * (AD-11) so the "first forecast" date honours the 09:00 ET send boundary
     * and never drifts from the success screen or the daily selector.
     */
    private function firstForecastDate(): CarbonImmutable
    {
        return app(CadencePredicate::class)
            ->firstSendDate($this->trip, CarbonImmutable::now('America/New_York'));
    }
}
