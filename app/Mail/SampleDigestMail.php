<?php

namespace App\Mail;

use App\Digest\CountdownLine;
use App\Digest\ForecastRows;
use App\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The public sample tripcast: a real-looking digest for a fixed demo trip, whose
 * footer CTA is a magic link ("Get started"). Unlike the daily digest it carries
 * NO unsubscribe/feedback/promo — a sample is a one-off, user-requested email,
 * not a subscription. Queued by the caller; renders from the passed snapshot.
 */
class SampleDigestMail extends Mailable
{
    /**
     * @param  array{days: list<array<string, mixed>>}  $snapshot
     */
    public function __construct(
        public Trip $trip,
        public array $snapshot,
        public string $getStartedUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your sample tripcast — '.$this->trip->canonical_place_name,
        );
    }

    public function content(): Content
    {
        $countdown = app(CountdownLine::class);
        $today = CarbonImmutable::now('America/New_York')->startOfDay();

        $days = app(ForecastRows::class)->project(
            $this->snapshot,
            $this->trip->departure_date->toDateString(),
            $this->trip->return_date->toDateString(),
            $this->trip->user->temperature_unit === User::UNIT_CELSIUS,
        );

        return new Content(
            view: 'emails.sample-digest',
            text: 'emails.sample-digest-text',
            with: [
                'canonicalPlaceName' => $this->trip->canonical_place_name,
                'placeShort' => $countdown->placeShort($this->trip),
                'headerLine' => $countdown->headerLine($this->trip, $today),
                'dateRange' => $countdown->dateRange($this->trip),
                'days' => $days,
                'getStartedUrl' => $this->getStartedUrl,
                'postalAddress' => config('tripcast.postal_address'),
            ],
        );
    }
}
