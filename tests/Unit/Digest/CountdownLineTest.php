<?php

use App\Digest\CadencePredicate;
use App\Digest\CountdownLine;
use App\Models\Trip;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:05:00', 'America/New_York'));
    $this->today = Carbon::now('America/New_York');
    $this->line = new CountdownLine(new CadencePredicate);
});

afterEach(function () {
    Carbon::setTestNow();
});

function countdownTrip(string $departure, string $return, string $place = 'Edinburgh, United Kingdom'): Trip
{
    return new Trip([
        'canonical_place_name' => $place,
        'departure_date' => $departure,
        'return_date' => $return,
    ]);
}

it('renders the pre-trip countdown with the plural day count', function () {
    $trip = countdownTrip('2026-07-04', '2026-07-11'); // departure today + 5

    expect($this->line->positionLine($trip, $this->today))->toBe('5 days until Edinburgh')
        ->and($this->line->subjectSuffix($trip, $this->today))->toBe('5 days to go');
});

it('renders the singular one-day-until boundary', function () {
    $trip = countdownTrip('2026-06-30', '2026-07-07'); // departure tomorrow

    expect($this->line->positionLine($trip, $this->today))->toBe('1 day until Edinburgh')
        ->and($this->line->subjectSuffix($trip, $this->today))->toBe('1 day to go');
});

it('renders the departure-day "Today" boundary', function () {
    $trip = countdownTrip('2026-06-29', '2026-07-06'); // departure today

    expect($this->line->positionLine($trip, $this->today))->toBe('Today: Edinburgh')
        ->and($this->line->subjectSuffix($trip, $this->today))->toBe('today');
});

it('renders the mid-trip day number (departure day is Day 1)', function () {
    $trip = countdownTrip('2026-06-28', '2026-07-05'); // departure yesterday → Day 2

    expect($this->line->positionLine($trip, $this->today))->toBe('Day 2 in Edinburgh')
        ->and($this->line->subjectSuffix($trip, $this->today))->toBe('day 2');
});

it('renders the last-day boundary when today is the return date', function () {
    $trip = countdownTrip('2026-06-25', '2026-06-29'); // return today

    expect($this->line->positionLine($trip, $this->today))->toBe('Last day in Edinburgh')
        ->and($this->line->subjectSuffix($trip, $this->today))->toBe('last day');
});

it('uses the city portion before the comma as the place', function () {
    $trip = countdownTrip('2026-07-04', '2026-07-11', 'Paris, Île-de-France, France');

    expect($this->line->placeShort($trip))->toBe('Paris');
});
