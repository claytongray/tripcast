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
