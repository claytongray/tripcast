<?php

namespace App\Services\Metrics;

use App\Digest\CadencePredicate;

/**
 * Forward-looking digest projection for the admin Emails section: how many
 * digests are due to send tomorrow (and to which destinations), plus a 7-day
 * outlook. Derived entirely from the cadence authority (AD-11, {@see
 * CadencePredicate}), evaluated on the America/New_York send clock (AD-7).
 * Read-only; an estimate that assumes no trip/eligibility changes before then.
 */
class SendProjection
{
    private const FORWARD_DAYS = 7;

    private const TOP_DESTINATIONS_LIMIT = 10;

    public function __construct(private readonly CadencePredicate $cadence) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        // "Today" is the fixed America/New_York send clock (AD-7); projection
        // starts with tomorrow.
        $today = now('America/New_York')->startOfDay();
        $tomorrow = $today->addDay();

        $dueTomorrow = $this->cadence->dueOn($tomorrow);

        $destinations = [];
        foreach ($dueTomorrow->groupBy('canonical_place_name') as $place => $trips) {
            $destinations[] = ['destination' => (string) $place, 'count' => $trips->count()];
        }
        usort($destinations, fn (array $a, array $b) => $b['count'] <=> $a['count']
            ?: strcmp((string) $a['destination'], (string) $b['destination']));
        $destinations = array_slice($destinations, 0, self::TOP_DESTINATIONS_LIMIT);

        // 7-day outlook (tomorrow = day 1), each day's due count from the same
        // cadence authority.
        $dates = [];
        $counts = [];
        for ($offset = 1; $offset <= self::FORWARD_DAYS; $offset++) {
            $day = $today->addDays($offset);
            $dates[] = $day->toDateString();
            $counts[] = $offset === 1
                ? $dueTomorrow->count() // reuse the already-loaded set for day 1
                : $this->cadence->dueCountOn($day);
        }

        return [
            'tomorrow' => [
                'date' => $tomorrow->toDateString(),
                'count' => $dueTomorrow->count(),
                'destinations' => $destinations,
            ],
            'forward' => [
                'dates' => $dates,
                'counts' => $counts,
            ],
        ];
    }
}
