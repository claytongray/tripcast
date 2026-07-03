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

// AC1/AC2 — render: subject, destination, dates, first-digest date, sample CTA, plain-text twin.
it('renders a calm welcome with the locked copy and the sample CTA', function () {
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
    $mail->assertHasSubject("You're all set for Edinburgh");
    // departure − 7 days = July 7, 2026 (pre-window relative to June 29 today).
    $fragment = 'Your tripcast for Edinburgh, United Kingdom is set — July 14–21, 2026. Your first forecast arrives July 7, 2026. Nothing to do until then';
    $mail->assertSeeInHtml($fragment, false);
    $mail->assertSeeInText($fragment);
    // Three anchors: the signed sample CTA (Task 4) + two quiet legal-footer
    // Privacy/Terms links (Story 9.1).
    expect(substr_count($mail->render(), '<a '))->toBe(3);
});

// Story 9.1 (FR-26) — the welcome gains a legal footer: Privacy/Terms links
// (absolute URLs) + the postal address, in both twins.
it('renders privacy and terms links and the postal address in the footer', function () {
    config(['tripcast.postal_address' => 'Tripcast, 123 Main St, Anytown']);
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

    $mail->assertSeeInHtml(route('privacy'), false);
    $mail->assertSeeInHtml(route('terms'), false);
    $mail->assertSeeInHtml('Tripcast, 123 Main St, Anytown');

    $mail->assertSeeInText('Privacy: '.route('privacy'));
    $mail->assertSeeInText('Terms: '.route('terms'));
    $mail->assertSeeInText('Tripcast, 123 Main St, Anytown');
});

// Task 4: the out-of-window WelcomeMail gains a signed "see a sample now" CTA.
it('includes a signed sample CTA in the heads-up welcome', function () {
    $trip = User::factory()->confirmed()->create()->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533, 'longitude' => -3.1883,
        'departure_date' => '2026-08-31', 'return_date' => '2026-09-07',
        'status' => Trip::STATUS_ACTIVE,
    ]);

    $rendered = (new WelcomeMail($trip))->render();

    expect($rendered)->toContain('/sample/from-welcome/'.$trip->user->id)
        ->and($rendered)->toContain('sample');
});

// The first-forecast date honours the 09:00 ET send boundary (shared cadence
// authority): an in-window trip created after today's send first sends tomorrow.
it('shows tomorrow as the first forecast when today\'s send has passed', function () {
    // now is pinned to 12:00 ET (after 09:00). departure 2026-07-02 → window open
    // 2026-06-25 (before today) → today's send is gone → first forecast is June 30.
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

    (new WelcomeMail($trip))->assertSeeInText('arrives June 30, 2026.');
});
