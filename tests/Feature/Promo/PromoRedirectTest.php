<?php

use App\Models\PromoEvent;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\URL;

// Absolute link, relative signature — mirrors DigestMail and the signed:relative
// route (immune to MailerSend click-tracking's scheme rewrite).
function clickUrl(Trip $trip, string $slug = 'packing-cubes', string $sendDate = '2026-06-29'): string
{
    return url(URL::signedRoute('promo.click', ['trip' => $trip->id, 'slug' => $slug, 'send_date' => $sendDate], absolute: false));
}

it('logs a click and forwards to the tagged Amazon URL on a valid signed link', function () {
    config(['tripcast.promo.amazon_tag' => 'mytag-99']);
    $trip = Trip::factory()->for(User::factory()->confirmed())->create();

    $this->get(clickUrl($trip))
        ->assertRedirectContains('https://www.amazon.com/')
        ->assertRedirectContains('tag=mytag-99');

    $this->assertDatabaseHas('promo_events', [
        'trip_id' => $trip->id,
        'user_id' => $trip->user_id,
        'send_date' => '2026-06-29',
        'promo_slug' => 'packing-cubes',
        'event' => PromoEvent::EVENT_CLICK,
    ]);
});

it('is idempotent — a re-click (or prefetch) never double-logs', function () {
    $trip = Trip::factory()->for(User::factory()->confirmed())->create();
    $url = clickUrl($trip);

    $this->get($url)->assertRedirect();
    $this->get($url)->assertRedirect();

    expect(PromoEvent::where('event', PromoEvent::EVENT_CLICK)->count())->toBe(1);
});

it('rejects an unsigned or tampered link with 403 and logs nothing', function () {
    $trip = Trip::factory()->for(User::factory()->confirmed())->create();

    // Unsigned (no signature query param).
    $this->get(route('promo.click', ['trip' => $trip->id, 'slug' => 'packing-cubes', 'send_date' => '2026-06-29']))
        ->assertForbidden();

    // Tampered (slug changed after signing).
    $this->get(clickUrl($trip).'&slug=packing-cubes-tampered')->assertForbidden();

    expect(PromoEvent::count())->toBe(0);
});

it('404s on an unknown slug', function () {
    $trip = Trip::factory()->for(User::factory()->confirmed())->create();

    $this->get(clickUrl($trip, slug: 'no-such-product'))->assertNotFound();

    expect(PromoEvent::count())->toBe(0);
});
