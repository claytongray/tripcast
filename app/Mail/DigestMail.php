<?php

namespace App\Mail;

use App\Digest\CountdownLine;
use App\Digest\WeatherEmoji;
use App\Models\Trip;
use App\Models\User;
use App\Services\Promo\Promo;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/**
 * The daily morning digest (FR-7, AD-7, AD-9, UX-DR5): the countdown/position
 * line + the forecast — clipped to the trip's own window [departure, return] —
 * rendered entirely from the persisted snapshot (email_logs.weather_snapshot),
 * never re-fetching weather.
 *
 * Deliberately NOT `ShouldQueue`: it is sent synchronously inside the already
 * queued SendTripDigest job, which owns the bounded retry + terminal state
 * (AD-4). Driver-agnostic (Mailtrap local, MailerSend prod).
 */
class DigestMail extends Mailable
{
    private CountdownLine $countdown;

    private CarbonImmutable $today;

    private string $sendDate;

    /**
     * @param  array{days: list<array<string, mixed>>, limited: bool}  $snapshot
     */
    public function __construct(
        public Trip $trip,
        public array $snapshot,
        string $sendDate,
        public ?string $narration = null,
        public ?Promo $promo = null,
    ) {
        $this->countdown = app(CountdownLine::class);
        // The countdown is anchored on the America/New_York "today" (AD-7); the
        // send_date is that calendar date — kept raw for the feedback key (AD-9).
        $this->sendDate = $sendDate;
        $this->today = CarbonImmutable::parse($sendDate, 'America/New_York')->startOfDay();
    }

    public function envelope(): Envelope
    {
        // Place leads, countdown is the hook, the weather verdict never appears
        // in the subject (UX-DR16): "Edinburgh — 5 days to go".
        return new Envelope(
            subject: $this->countdown->placeShort($this->trip)
                .' — '.$this->countdown->subjectSuffix($this->trip, $this->today),
        );
    }

    public function headers(): Headers
    {
        // One-click unsubscribe (RFC 8058 / deliverability, UX-DR17): a signed
        // HTTPS POST target the mail client fires directly, plus a mailto arm.
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->oneClickUnsubscribeUrl().'>, '
                .'<mailto:'.config('tripcast.unsubscribe_mailto').'?subject=unsubscribe>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $days = $this->dayRows();

        return new Content(
            view: 'emails.digest',
            text: 'emails.digest-text',
            with: [
                'place' => $this->trip->canonical_place_name,
                'placeShort' => $this->countdown->placeShort($this->trip),
                'positionLine' => $this->countdown->positionLine($this->trip, $this->today),
                // Optional calm day-over-day line (AD-17, UX-DR5); omitted when null.
                'narration' => $this->narration,
                // Optional weather-keyed affiliate promo (AD-18); omitted when null.
                // The link is the signed redirect (FR-18) — no raw affiliate URL
                // in the body; attribution + forwarding happen at PromoRedirect.
                'promo' => $this->promo,
                'promoUrl' => $this->promoUrl(),
                'days' => $days,
                'limited' => $this->forecastIsLimited($days),
                'limitedLine' => "Limited data today — we'll have the full picture tomorrow.",
                'postalAddress' => config('tripcast.postal_address'),
                // Signed, trip/user-scoped footer actions (FR-5, AD-6); permanent
                // signatures (an emailed link must not expire; the action is
                // confirm-gated + idempotent).
                'endTripUrl' => URL::signedRoute('email.trip.end', ['trip' => $this->trip->id]),
                'unsubscribeUrl' => URL::signedRoute('email.unsubscribe', ['user' => $this->trip->user->id]),
                // One-tap feedback chips (FR-8): signed, scoped to trip + send_date.
                'helpedUrl' => $this->feedbackUrl('helped'),
                'notHelpfulUrl' => $this->feedbackUrl('not_helpful'),
            ],
        );
    }

    /**
     * The signed promo-click redirect (FR-18, AD-18): a permanent-signature GET
     * that logs attribution then forwards to Amazon. Null when no promo shows, so
     * the body never carries a raw affiliate link.
     */
    private function promoUrl(): ?string
    {
        if ($this->promo === null) {
            return null;
        }

        return URL::signedRoute('promo.click', [
            'trip' => $this->trip->id,
            'slug' => $this->promo->slug,
            'send_date' => $this->sendDate,
        ]);
    }

    /**
     * A signed feedback-chip URL for one reaction, scoped to the trip + send_date
     * (the upsert key, AD-9). Permanent signature; the send_date query param is
     * covered by it, so the recorded row can't be forged.
     */
    private function feedbackUrl(string $reaction): string
    {
        return URL::signedRoute('email.trip.feedback', [
            'trip' => $this->trip->id,
            'reaction' => $reaction,
            'send_date' => $this->sendDate,
        ]);
    }

    /**
     * The signed HTTPS one-click unsubscribe target for the List-Unsubscribe-Post
     * header (account-scoped, AD-13).
     */
    private function oneClickUnsubscribeUrl(): string
    {
        return URL::signedRoute('email.unsubscribe.one_click', ['user' => $this->trip->user->id]);
    }

    /**
     * Project the snapshot days into render-ready rows in the owner's single
     * preferred unit (default Fahrenheit). The forecast is clipped to the trip's
     * own window [departure, return]: only the trip's days render — never the
     * pre-departure or post-return snapshot days — so a 4-day trip shows exactly
     * 4 rows, departure first. A day missing any core value is `limited` and
     * renders the calm marker — never fabricated values (FR-7). The snapshot
     * carries both units; we pick one here and round to whole degrees (JSON may
     * normalize 20.0 → 20, so cast defensively). A decorative weather emoji
     * accompanies the condition text (never replaces it, UX-DR6).
     *
     * @return list<array{label: string, limited: bool, isDeparture: bool, conditionText: ?string, emoji: string, precipChance: ?int, high: ?int, low: ?int}>
     */
    private function dayRows(): array
    {
        $celsius = $this->trip->user->temperature_unit === User::UNIT_CELSIUS;
        $departureDate = $this->trip->departure_date->toDateString();
        $returnDate = $this->trip->return_date->toDateString();

        $tripDays = array_values(array_filter(
            $this->snapshot['days'],
            fn (array $day): bool => $day['date'] >= $departureDate && $day['date'] <= $returnDate,
        ));

        return array_map(function (array $day) use ($celsius, $departureDate): array {
            $limited = $day['conditionText'] === null
                || ($day['precipChance'] ?? null) === null
                || ($day['highF'] ?? null) === null
                || ($day['highC'] ?? null) === null
                || ($day['lowF'] ?? null) === null
                || ($day['lowC'] ?? null) === null;

            $high = $celsius ? ($day['highC'] ?? null) : ($day['highF'] ?? null);
            $low = $celsius ? ($day['lowC'] ?? null) : ($day['lowF'] ?? null);

            return [
                // Destination-local calendar date exactly as stored (AD-7); naive
                // date string, so no timezone is applied.
                'label' => CarbonImmutable::parse($day['date'])->format('D j M'),
                'limited' => $limited,
                // The trip's departure day (the first row when in range) gets
                // the trip-start tag.
                'isDeparture' => $day['date'] === $departureDate,
                'conditionText' => $day['conditionText'] ?? null,
                'emoji' => $limited ? '' : WeatherEmoji::for($day['conditionText'] ?? null),
                'precipChance' => $limited ? null : (int) $day['precipChance'],
                'high' => $limited ? null : (int) round((float) $high),
                'low' => $limited ? null : (int) round((float) $low),
            ];
        }, $tripDays);
    }

    /**
     * Whether the forecast-level calm line shows. The displayed (trip-window)
     * forecast is "limited" when any shown day is missing core values, or when
     * the forecast doesn't yet reach the trip's end — later trip days are still
     * beyond the horizon and arrive as the trip nears ("we'll have the full
     * picture tomorrow", FR-7). Covers the empty case too (no trip day in range
     * yet, the very first cadence send).
     *
     * @param  list<array{limited: bool}>  $days
     */
    private function forecastIsLimited(array $days): bool
    {
        if ($days === [] || ! $this->forecastReachesTripEnd()) {
            return true;
        }

        foreach ($days as $day) {
            if ($day['limited']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the fetched forecast extends to (or past) the trip's return date —
     * i.e. every trip day is already within the horizon. When it falls short,
     * the remaining trip days roll into later sends.
     */
    private function forecastReachesTripEnd(): bool
    {
        $dates = array_column($this->snapshot['days'], 'date');

        if ($dates === []) {
            return false;
        }

        return max($dates) >= $this->trip->return_date->toDateString();
    }
}
