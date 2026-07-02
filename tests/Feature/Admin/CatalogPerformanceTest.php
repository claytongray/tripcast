<?php

use App\Models\PromoEvent;
use App\Models\PromoItem;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Story 8.5 — the catalog list surfaces each item's impressions/clicks/CTR over a
 * 7/30/90-day window (FR-25), joined `promo_events.promo_slug → promo_items.slug`,
 * read-only and behind the single admin Gate (AD-12).
 */
beforeEach(function () {
    $this->travelTo('2026-07-01 12:00:00'); // default 30-day window: [2026-06-02, 2026-07-01]
    $this->admin = User::factory()->admin()->confirmed()->create();
});

/** Insert one promo_events row per date (unique key is [trip, send_date, slug, event]). */
function insertCatalogEvent(Trip $trip, string $slug, string $event, array $dates): void
{
    foreach ($dates as $date) {
        DB::table('promo_events')->insert([
            'trip_id' => $trip->id,
            'user_id' => $trip->user_id,
            'send_date' => $date,
            'promo_slug' => $slug,
            'event' => $event,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

it('surfaces per-item impressions, clicks and CTR on the catalog list', function () {
    $earner = PromoItem::factory()->forProfile(PromoItem::PROFILE_HOT)->create(['slug' => 'earner', 'sort_order' => 0]);
    $quiet = PromoItem::factory()->forProfile(PromoItem::PROFILE_HOT)->create(['slug' => 'quiet', 'sort_order' => 1]);

    $trip = Trip::factory()->for(User::factory())->create();
    // earner: 4 impressions, 1 click → 25% CTR
    insertCatalogEvent($trip, 'earner', PromoEvent::EVENT_IMPRESSION, ['2026-06-10', '2026-06-11', '2026-06-12', '2026-06-13']);
    insertCatalogEvent($trip, 'earner', PromoEvent::EVENT_CLICK, ['2026-06-10']);

    $this->actingAs($this->admin)
        ->get(route('admin.promo-items.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Catalog/Index')
            ->where('window', 30)
            ->where('items', fn ($items) => collect($items)->firstWhere('slug', 'earner')['impressions'] === 4
                && collect($items)->firstWhere('slug', 'earner')['clicks'] === 1
                && (float) collect($items)->firstWhere('slug', 'earner')['ctr'] === 25.0
                // an item with no events reads zeroes
                && collect($items)->firstWhere('slug', 'quiet')['impressions'] === 0
                && collect($items)->firstWhere('slug', 'quiet')['clicks'] === 0
                && (float) collect($items)->firstWhere('slug', 'quiet')['ctr'] === 0.0));
    unset($earner, $quiet);
});

it('excludes events outside the selected window', function () {
    PromoItem::factory()->forProfile(PromoItem::PROFILE_HOT)->create(['slug' => 'earner']);

    $trip = Trip::factory()->for(User::factory())->create();
    // 1 in the 7-day window [2026-06-25, 2026-07-01], 1 before it.
    insertCatalogEvent($trip, 'earner', PromoEvent::EVENT_IMPRESSION, ['2026-06-28', '2026-06-10']);

    $this->actingAs($this->admin)
        ->get(route('admin.promo-items.index', ['days' => 7]))
        ->assertInertia(fn ($page) => $page
            ->where('window', 7)
            ->where('items', fn ($items) => collect($items)->firstWhere('slug', 'earner')['impressions'] === 1));
});

it('falls back to the default 30-day window on invalid input', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.promo-items.index', ['days' => 'nope']))
        ->assertInertia(fn ($page) => $page->where('window', 30));
});

it('guards the catalog list behind the admin Gate', function () {
    $this->get(route('admin.promo-items.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->confirmed()->create())
        ->get(route('admin.promo-items.index'))
        ->assertForbidden();
});
