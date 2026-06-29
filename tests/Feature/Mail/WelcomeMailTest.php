<?php

use App\Actions\CreateTrip;
use App\Mail\WelcomeMail;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function tripDetails(array $overrides = []): array
{
    return array_merge([
        'destination' => 'Edinburgh',
        'departure_date' => '2026-07-14',
        'return_date' => '2026-07-21',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533,
        'longitude' => -3.1883,
    ], $overrides);
}

// Welcome fires at creation only for an already-confirmed owner (logged-in add).
it('queues the welcome on creation for a confirmed owner', function () {
    User::factory()->confirmed()->create(['email' => 'maya@example.com']);

    app(CreateTrip::class)->handle('maya@example.com', tripDetails());

    Mail::assertQueued(WelcomeMail::class, fn (WelcomeMail $mail) => $mail->hasTo('maya@example.com'));
});

// A new, unconfirmed signup is NOT welcomed at creation (it's welcomed at confirmation).
it('does not queue the welcome on creation for an unconfirmed owner', function () {
    app(CreateTrip::class)->handle('newcomer@example.com', tripDetails());

    Mail::assertNotQueued(WelcomeMail::class);
});

// AC2 — opted-out owner gets no welcome even when confirmed.
it('does not queue the welcome for an opted-out owner', function () {
    User::factory()->confirmed()->optedOut()->create(['email' => 'maya@example.com']);

    app(CreateTrip::class)->handle('maya@example.com', tripDetails());

    Mail::assertNotQueued(WelcomeMail::class);
});

// AC1 — a failed create queues no welcome (post-commit only).
it('queues no welcome when creation fails', function () {
    try {
        app(CreateTrip::class)->handle('maya@example.com', tripDetails([
            'canonical_place_name' => str_repeat('x', 300),
        ]));
    } catch (Throwable) {
        // expected
    }

    Mail::assertNotQueued(WelcomeMail::class);
    expect(User::count())->toBe(0);
});

// AC1/AC2 — render: subject, destination, dates, first-digest date, no CTA, plain-text twin.
it('renders a calm welcome with the locked copy and no CTA', function () {
    $user = User::factory()->create(['email' => 'maya@example.com']);
    $trip = $user->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533,
        'longitude' => -3.1883,
        'departure_date' => '2026-07-14',
        'return_date' => '2026-07-21',
        'status' => Trip::STATUS_ACTIVE,
    ]);

    $mail = new WelcomeMail($trip);
    $mail->assertHasSubject("We're watching Edinburgh");
    // departure − 7 days = 7 July (pre-window relative to 29 June today).
    $fragment = 'watching Edinburgh, United Kingdom, 14–21 July. Your first morning forecast arrives 7 July. Nothing to do until then';
    $mail->assertSeeInHtml($fragment, false);
    $mail->assertSeeInText($fragment);
    $mail->assertDontSeeInHtml('<a ', false); // no CTA/button/link
});

// firstDigestDate floors to today for in-window trips.
it('floors the first-digest date to today for an in-window trip', function () {
    // departure 2026-07-02 → window open 2026-06-25 (before today 2026-06-29) → today.
    $user = User::factory()->create();
    $trip = $user->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533,
        'longitude' => -3.1883,
        'departure_date' => '2026-07-02',
        'return_date' => '2026-07-09',
        'status' => Trip::STATUS_ACTIVE,
    ]);

    (new WelcomeMail($trip))->assertSeeInText('arrives 29 June.');
});
