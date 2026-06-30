<?php

namespace App\Digest;

use App\Models\Trip;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * The single authority for digest cadence (AD-11): "is this Trip due a Daily
 * Digest on date D, and how many days until departure". Both the daily selector
 * and the dashboard countdown derive from here — never a second implementation.
 *
 * Due ⟺ status active AND not soft-deleted AND owner confirmed (AD-6) AND owner
 * not opted out (AD-13) AND D ∈ [departure − horizon, return], where `horizon`
 * is the configured forecast reach (`tripcast.forecast.horizon_days`): the send
 * window opens exactly when the departure day first enters the forecast. All
 * dates are the destination/trip naive calendar dates; D is the America/New_York
 * "today" (AD-7).
 */
class CadencePredicate
{
    /**
     * The fixed daily send hour on the America/New_York clock — mirrors the
     * `digests:send` schedule (`->dailyAt('09:00')`, AD-2/AD-7). Kept in sync by
     * hand: there is one scheduler and one predicate.
     */
    private const SEND_HOUR = 9;

    /**
     * Evaluate a loaded Trip against all cadence clauses for date D.
     */
    public function isDue(Trip $trip, CarbonInterface $date): bool
    {
        if ($trip->status !== Trip::STATUS_ACTIVE || $trip->deleted_at !== null) {
            return false;
        }

        $user = $trip->user;

        if ($user->email_verified_at === null || $user->email_opted_out) {
            return false;
        }

        // Compare on calendar dates (Y-m-d strings sort chronologically) so the
        // naive DATE columns and the America/New_York "today" never disagree by a
        // timezone offset.
        $day = $date->toDateString();
        $windowOpen = $trip->departure_date->copy()->subDays($this->horizonDays())->toDateString();
        $windowClose = $trip->return_date->toDateString();

        return $windowOpen <= $day && $day <= $windowClose;
    }

    /**
     * The due-set selector (the query form of isDue) for date D.
     *
     * D ∈ [departure − horizon, return] ⟺ departure <= D + horizon AND return >= D.
     *
     * @return Collection<int, Trip>
     */
    public function dueOn(CarbonInterface $date): Collection
    {
        $day = $date->copy()->startOfDay();

        return Trip::query()
            ->where('status', Trip::STATUS_ACTIVE)
            ->whereDate('departure_date', '<=', $day->copy()->addDays($this->horizonDays())->toDateString())
            ->whereDate('return_date', '>=', $day->toDateString())
            ->whereHas('user', function ($query): void {
                $query->whereNotNull('email_verified_at')->where('email_opted_out', false);
            })
            ->get();
    }

    /**
     * The forecast reach (`tripcast.forecast.horizon_days`): how many days ahead
     * the weather API forecasts, and so how many days before departure the send
     * window opens. One knob — bump it as the upstream API's reach grows.
     */
    private function horizonDays(): int
    {
        return (int) config('tripcast.forecast.horizon_days');
    }

    /**
     * The first calendar date this Trip's daily digest will send: the later of D
     * and the window open (`departure − horizon`). The single authority behind
     * the "your first forecast goes out {date}" success copy (Story 3.2), on the
     * America/New_York calendar (AD-7/AD-11).
     */
    public function firstSendDate(Trip $trip, CarbonInterface $date): CarbonImmutable
    {
        $today = CarbonImmutable::parse($date->toDateString());
        $windowOpen = CarbonImmutable::parse($trip->departure_date->toDateString())
            ->subDays($this->horizonDays());

        return $today->greaterThan($windowOpen) ? $today : $windowOpen;
    }

    /**
     * The next calendar date this Trip's daily digest will send, or null when none
     * is upcoming (ineligible, paused/completed, or past the send window). The
     * display-facing companion to {@see isDue}: it answers "when next?" rather than
     * "due today?", applying the fixed 09:00 America/New_York send clock (AD-2/AD-7).
     *
     * Before the window it returns the window-open date (first send); inside the
     * window it returns today (if `$now` is before the send hour) or tomorrow,
     * clamped to the return date; past the window it returns null.
     */
    public function nextSendDate(Trip $trip, CarbonInterface $now): ?CarbonImmutable
    {
        if ($trip->status !== Trip::STATUS_ACTIVE || $trip->deleted_at !== null) {
            return null;
        }

        $user = $trip->user;

        if ($user->email_verified_at === null || $user->email_opted_out) {
            return null;
        }

        // Today's send has gone out once the clock passes the send hour, so the
        // earliest candidate is tomorrow; before the send hour, today still counts.
        $start = CarbonImmutable::parse($now->toDateString());

        if ($now->hour >= self::SEND_HOUR) {
            $start = $start->addDay();
        }

        $windowOpen = CarbonImmutable::parse($trip->departure_date->toDateString())
            ->subDays($this->horizonDays());
        $windowClose = CarbonImmutable::parse($trip->return_date->toDateString());

        $next = $start->lessThan($windowOpen) ? $windowOpen : $start;

        return $next->greaterThan($windowClose) ? null : $next;
    }

    /**
     * Days from D until the Trip's departure (negative once the trip has begun) —
     * the shared math behind the digest countdown (Story 2.4) and dashboard (Epic 3).
     */
    public function daysUntilDeparture(Trip $trip, CarbonInterface $date): int
    {
        // Whole-calendar-day diff from each side's date (Y-m-d), timezone-agnostic.
        $from = CarbonImmutable::parse($date->toDateString());
        $to = CarbonImmutable::parse($trip->departure_date->toDateString());

        return (int) $from->diffInDays($to, false);
    }
}
