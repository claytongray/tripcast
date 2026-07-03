<?php

namespace App\Mail;

use App\Digest\CountdownLine;
use App\Digest\ForecastRows;
use App\Models\Trip;
use App\Models\User;
use App\Services\Promo\Promo;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
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
 *
 * Sets NO custom List-Unsubscribe / List-Unsubscribe-Post headers (removed
 * 2026-07-02, Story 9.9): custom headers are Professional/Enterprise-only on
 * MailerSend and 422 every send on the current plan (#MS42235), while
 * MailerSend injects its own managed List-Unsubscribe header at send time on
 * every plan. The signed body-link unsubscribe (FR-5) is the primary user
 * path; the re-enable recipe lives in deferred-work.md.
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

    public function content(): Content
    {
        $days = $this->dayRows();
        $pending = $this->pendingTripDates();

        return new Content(
            view: 'emails.digest',
            text: 'emails.digest-text',
            with: [
                'place' => $this->trip->canonical_place_name,
                'placeShort' => $this->countdown->placeShort($this->trip),
                // Header: the place is the heading, so the sub-line is the
                // countdown ("5 days to go!") and the trip dates sit below it —
                // the place is never repeated (UX).
                'headerLine' => $this->countdown->headerLine($this->trip, $this->today),
                'dateRange' => $this->countdown->dateRange($this->trip),
                // Itinerary days still beyond the forecast horizon (FR-7),
                // collapsed into one calm line rather than one row each so a long
                // trip never lists a dozen "no data yet" rows.
                'futureRange' => $this->futureRange($pending),
                'futureNote' => $this->futureNote($pending),
                // Optional calm day-over-day line (AD-17, UX-DR5); omitted when null.
                'narration' => $this->narration,
                // Optional weather-keyed affiliate promo (AD-18); omitted when null.
                // The link is the signed redirect (FR-18) — no raw affiliate URL
                // in the body; attribution + forwarding happen at PromoRedirect.
                'promo' => $this->promo,
                'promoUrl' => $this->promoUrl(),
                'days' => $days,
                'limited' => $this->forecastHasDataGap($days),
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
     * @return list<array{label: string, limited: bool, isDeparture: bool, conditionText: ?string, emoji: string, precipChance: ?int, high: ?int, low: ?int, humidity: ?int, feelsLike: ?int}>
     */
    private function dayRows(): array
    {
        return app(ForecastRows::class)->project(
            $this->snapshot,
            $this->trip->departure_date->toDateString(),
            $this->trip->return_date->toDateString(),
            $this->trip->user->temperature_unit === User::UNIT_CELSIUS,
        );
    }

    /**
     * Whether the forecast-level calm line shows. Now scoped to a genuine data
     * gap: a shown (in-window) trip day is missing core values. Trip days still
     * beyond the horizon are no longer "limited" — they render as the collapsed
     * future line instead (see {@see self::futureRange()}), which says plainly
     * when their forecast arrives.
     *
     * @param  list<array{limited: bool}>  $days
     */
    private function forecastHasDataGap(array $days): bool
    {
        foreach ($days as $day) {
            if ($day['limited']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Trip days that the forecast horizon does not yet reach: dates in the trip
     * window [departure, return] that are today-or-later but absent from the
     * snapshot. They are always the trailing dates (the snapshot is contiguous
     * from today), so they collapse into one "forecast arrives later" line
     * rather than a row each.
     *
     * @return list<string>
     */
    private function pendingTripDates(): array
    {
        $snapshotDates = array_flip(array_column($this->snapshot['days'], 'date'));
        $today = $this->today->toDateString();
        $returnDate = $this->trip->return_date->toDateString();

        $pending = [];
        $cursor = CarbonImmutable::parse($this->trip->departure_date->toDateString());

        while ($cursor->toDateString() <= $returnDate) {
            $date = $cursor->toDateString();
            if ($date >= $today && ! isset($snapshotDates[$date])) {
                $pending[] = $date;
            }
            $cursor = $cursor->addDay();
        }

        return $pending;
    }

    /**
     * The date span of the still-beyond-horizon trip days (e.g. "8–14 Jul"),
     * or null when the forecast already reaches the trip's end.
     *
     * @param  list<string>  $pending
     */
    private function futureRange(array $pending): ?string
    {
        if ($pending === []) {
            return null;
        }

        return $this->countdown->formatRange(
            CarbonImmutable::parse($pending[0]),
            CarbonImmutable::parse((string) end($pending)),
        );
    }

    /**
     * The calm explainer for the collapsed future-days line: the forecast
     * appears once those days move within the configured horizon (FR-7). Null
     * when there are no pending days.
     *
     * @param  list<string>  $pending
     */
    private function futureNote(array $pending): ?string
    {
        if ($pending === []) {
            return null;
        }

        $horizon = (int) config('tripcast.forecast.horizon_days');
        $subject = count($pending) === 1 ? 'this day is' : 'these days are';

        return "Forecast appears once {$subject} within {$horizon} days";
    }
}
