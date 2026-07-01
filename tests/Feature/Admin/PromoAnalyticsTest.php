<?php

use App\Models\PromoEvent;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-07-01 12:00:00'); // default 30-day window: [2026-06-02, 2026-07-01]
    $this->admin = User::factory()->admin()->confirmed()->create();
});

/** Insert one promo_events row per date (unique key is [trip, send_date, slug, event]). */
function seedPromo(Trip $trip, string $slug, string $event, array $dates): void
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

function seedPromoFixture(): void
{
    $trip = Trip::factory()->for(User::factory())->create();

    // hot: 4 impressions, 1 click (CTR 25%)
    seedPromo($trip, 'packable-sun-hat', PromoEvent::EVENT_IMPRESSION, ['2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05']);
    seedPromo($trip, 'packable-sun-hat', PromoEvent::EVENT_CLICK, ['2026-06-02']);
    // cold: 2 impressions, 1 click (50%)
    seedPromo($trip, 'merino-base-layer', PromoEvent::EVENT_IMPRESSION, ['2026-06-06', '2026-06-07']);
    seedPromo($trip, 'merino-base-layer', PromoEvent::EVENT_CLICK, ['2026-06-06']);
    // travel-essentials: 3 impressions, 0 clicks (0%)
    seedPromo($trip, 'universal-adapter', PromoEvent::EVENT_IMPRESSION, ['2026-06-08', '2026-06-09', '2026-06-10']);
    // unknown (not in catalog): 2 impressions, 1 click (50%)
    seedPromo($trip, 'mystery-widget', PromoEvent::EVENT_IMPRESSION, ['2026-06-11', '2026-06-12']);
    seedPromo($trip, 'mystery-widget', PromoEvent::EVENT_CLICK, ['2026-06-11']);
    // out of window — excluded
    seedPromo($trip, 'packable-sun-hat', PromoEvent::EVENT_IMPRESSION, ['2026-05-01']);
}

it('reports impressions, clicks and CTR by slug and by weather profile', function () {
    seedPromoFixture();

    $this->actingAs($this->admin)
        ->get(route('admin.promos'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Promos')
            ->where('totals.impressions', 11)
            ->where('totals.clicks', 3)
            ->where('totals.ctr', fn ($v) => (float) $v === 27.3)
            // by_slug sorted by impressions desc, ties alphabetical
            ->where('by_slug.0.slug', 'packable-sun-hat')
            ->where('by_slug.0.impressions', 4)
            ->where('by_slug.0.clicks', 1)
            ->where('by_slug.0.ctr', fn ($v) => (float) $v === 25.0)
            ->where('by_slug.1.slug', 'universal-adapter')
            ->has('by_slug', 4)
            // by_profile groups by catalog profile; unmapped slug → 'unknown'
            ->where('by_profile', fn ($profiles) => collect($profiles)->firstWhere('profile', 'hot')['impressions'] === 4
                && collect($profiles)->firstWhere('profile', 'hot')['clicks'] === 1
                && collect($profiles)->firstWhere('profile', 'travel-essentials')['clicks'] === 0
                && collect($profiles)->pluck('profile')->contains('unknown')));
});

it('recomputes over a 7-day window and falls back on invalid input', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.promos', ['days' => 7]))
        ->assertInertia(fn ($page) => $page->where('window', 7));

    $this->actingAs($this->admin)
        ->get(route('admin.promos', ['days' => 'nope']))
        ->assertInertia(fn ($page) => $page->where('window', 30));
});

it('guards the promos section behind the admin Gate', function () {
    $this->get(route('admin.promos'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->confirmed()->create())
        ->get(route('admin.promos'))
        ->assertForbidden();
});
