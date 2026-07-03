<?php

use App\Models\PromoItem;
use App\Models\User;

/**
 * Story 8.3 — the catalog CRUD UI is the first *mutating* admin surface. It sits
 * behind the single admin Gate (AD-12): the group guards ALL six verbs incl.
 * writes. `slug` is the immutable attribution key (AD-18): set-once on edit,
 * unique across soft-deleted rows. Retirement is soft-delete, never force-delete,
 * so the 8.2 `findBySlug(withTrashed)` click path keeps resolving.
 */

/** A valid create payload (active Amazon item). */
function promoItemPayload(array $overrides = []): array
{
    return array_merge([
        'slug' => 'merino-base-layer-x',
        'label' => 'Merino wool base layer',
        'description' => null,
        'url' => 'https://www.amazon.com/dp/B000EXAMPLE1',
        'merchant' => PromoItem::MERCHANT_AMAZON,
        'weather_profile' => PromoItem::PROFILE_COLD,
        'is_active' => true,
        'sort_order' => 0,
        'featured_from' => null,
        'featured_to' => null,
    ], $overrides);
}

// ---- Gate: every verb, guests → login ------------------------------------

it('redirects guests to login on read verbs', function (string $routeName) {
    $item = PromoItem::factory()->create();

    $this->get(route($routeName, $routeName === 'admin.promo-items.edit' ? $item : []))
        ->assertRedirect(route('login'));
})->with([
    'index' => ['admin.promo-items.index'],
    'create' => ['admin.promo-items.create'],
    'edit' => ['admin.promo-items.edit'],
]);

it('redirects guests to login on write verbs (store/update/destroy)', function () {
    $item = PromoItem::factory()->create();

    $this->post(route('admin.promo-items.store'), promoItemPayload())
        ->assertRedirect(route('login'));
    $this->put(route('admin.promo-items.update', $item), promoItemPayload())
        ->assertRedirect(route('login'));
    $this->delete(route('admin.promo-items.destroy', $item))
        ->assertRedirect(route('login'));
});

// ---- Gate: every verb, authed non-admin → 403 ----------------------------

it('forbids authenticated non-admins on read verbs', function (string $routeName) {
    $item = PromoItem::factory()->create();

    $this->actingAs(User::factory()->confirmed()->create())
        ->get(route($routeName, $routeName === 'admin.promo-items.edit' ? $item : []))
        ->assertForbidden();
})->with([
    'index' => ['admin.promo-items.index'],
    'create' => ['admin.promo-items.create'],
    'edit' => ['admin.promo-items.edit'],
]);

it('forbids authenticated non-admins on write verbs too', function () {
    $item = PromoItem::factory()->create();
    $user = User::factory()->confirmed()->create();

    $this->actingAs($user)->post(route('admin.promo-items.store'), promoItemPayload())
        ->assertForbidden();
    $this->actingAs($user)->put(route('admin.promo-items.update', $item), promoItemPayload())
        ->assertForbidden();
    $this->actingAs($user)->delete(route('admin.promo-items.destroy', $item))
        ->assertForbidden();

    // The write attempts left no trace.
    expect(PromoItem::count())->toBe(1);
    expect($item->fresh()->trashed())->toBeFalse();
});

// ---- Index / Create / Edit render (admin) --------------------------------

it('renders the index with a projected, non-trashed list for an admin', function () {
    PromoItem::factory()->forProfile(PromoItem::PROFILE_COLD)->create(['slug' => 'live-one']);
    PromoItem::factory()->trashed()->create(['slug' => 'retired-one']);

    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->get(route('admin.promo-items.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Catalog/Index')
            ->has('items', 1)
            ->where('items.0.slug', 'live-one')
        );
});

it('offers the create form without mild in the selectable profiles', function () {
    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->get(route('admin.promo-items.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Catalog/Form')
            ->where('item', null)
            ->where('profiles', fn ($profiles) => ! collect($profiles)->contains(PromoItem::PROFILE_MILD))
            ->where('merchants', fn ($m) => collect($m)->contains(PromoItem::MERCHANT_AMAZON))
        );
});

it('keeps a legacy mild item editable, showing mild in the edit profiles', function () {
    $item = PromoItem::factory()->forProfile(PromoItem::PROFILE_MILD)->create();

    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->get(route('admin.promo-items.edit', $item))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Catalog/Form')
            ->where('item.weather_profile', PromoItem::PROFILE_MILD)
            ->where('slugLocked', true)
            ->where('profiles', fn ($profiles) => collect($profiles)->contains(PromoItem::PROFILE_MILD))
        );
});

// ---- Store happy path ----------------------------------------------------

it('creates an item and redirects to the index with a flash status', function () {
    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->post(route('admin.promo-items.store'), promoItemPayload(['slug' => 'new-item']))
        ->assertRedirect(route('admin.promo-items.index'))
        ->assertSessionHas('status');

    $this->assertDatabaseHas('promo_items', [
        'slug' => 'new-item',
        'merchant' => PromoItem::MERCHANT_AMAZON,
        'weather_profile' => PromoItem::PROFILE_COLD,
        'is_active' => true,
    ]);
});

it('stores an other-merchant url verbatim', function () {
    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->post(route('admin.promo-items.store'), promoItemPayload([
            'slug' => 'rei-item',
            'merchant' => PromoItem::MERCHANT_OTHER,
            'url' => 'https://www.rei.com/product/12345',
        ]))
        ->assertRedirect(route('admin.promo-items.index'));

    $this->assertDatabaseHas('promo_items', [
        'slug' => 'rei-item',
        'url' => 'https://www.rei.com/product/12345',
    ]);
});

// ---- Validation ----------------------------------------------------------

it('rejects invalid payloads', function (array $bad, string $field) {
    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->post(route('admin.promo-items.store'), promoItemPayload($bad))
        ->assertSessionHasErrors($field);

    expect(PromoItem::count())->toBe(0);
})->with([
    'non-https image' => [['image_url' => 'http://placehold.co/x.png'], 'image_url'],
    'bad link scheme' => [['url' => 'ftp://example.com/x'], 'url'],
    'profile off taxonomy' => [['weather_profile' => 'tropical'], 'weather_profile'],
    'merchant off list' => [['merchant' => 'ebay'], 'merchant'],
    'featured_to before from' => [['featured_from' => '2026-08-10', 'featured_to' => '2026-08-01'], 'featured_to'],
    'missing label' => [['label' => ''], 'label'],
]);

it('rejects weather_profile=mild on create (no new mild items)', function () {
    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->post(route('admin.promo-items.store'), promoItemPayload([
            'slug' => 'sneaky-mild',
            'weather_profile' => PromoItem::PROFILE_MILD,
        ]))
        ->assertSessionHasErrors('weather_profile');

    expect(PromoItem::where('slug', 'sneaky-mild')->exists())->toBeFalse();
});

it('accepts a product url longer than 255 characters', function () {
    $longUrl = 'https://www.amazon.com/dp/B000EXAMPLE1?'.str_repeat('tag=abcdefgh&', 40); // ~500 chars

    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->post(route('admin.promo-items.store'), promoItemPayload([
            'slug' => 'long-url-item',
            'url' => $longUrl,
        ]))
        ->assertRedirect(route('admin.promo-items.index'))
        ->assertSessionHasNoErrors();

    expect(PromoItem::where('slug', 'long-url-item')->firstOrFail()->url)->toBe($longUrl);
});

it('requires featured_from when featured_to is given', function () {
    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->post(route('admin.promo-items.store'), promoItemPayload([
            'slug' => 'lopsided-window',
            'featured_from' => null,
            'featured_to' => '2026-08-01',
        ]))
        ->assertSessionHasErrors('featured_from');

    expect(PromoItem::where('slug', 'lopsided-window')->exists())->toBeFalse();
});

it('allows editing a legacy mild item without forcing a profile change', function () {
    $item = PromoItem::factory()->forProfile(PromoItem::PROFILE_MILD)->create();

    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->put(route('admin.promo-items.update', $item), promoItemPayload([
            'slug' => $item->slug,
            'weather_profile' => PromoItem::PROFILE_MILD,
            'label' => 'Updated mild label',
        ]))
        ->assertRedirect(route('admin.promo-items.index'))
        ->assertSessionHasNoErrors();

    expect($item->fresh()->label)->toBe('Updated mild label');
});

// ---- slug uniqueness spans soft-deleted rows -----------------------------

it('rejects a slug already used by a soft-deleted item and hints at restore', function () {
    PromoItem::factory()->trashed()->create(['slug' => 'taken-slug']);

    $response = $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->post(route('admin.promo-items.store'), promoItemPayload(['slug' => 'taken-slug']))
        ->assertSessionHasErrors('slug');

    $errors = session('errors')->get('slug');
    expect($errors[0])->toContain('retired');
});

// ---- slug is set-once on update ------------------------------------------

it('ignores a changed slug on update (set-once attribution key)', function () {
    $item = PromoItem::factory()->create(['slug' => 'original-slug']);

    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->put(route('admin.promo-items.update', $item), promoItemPayload([
            'slug' => 'a-different-slug',
            'label' => 'Renamed label',
        ]))
        ->assertRedirect(route('admin.promo-items.index'));

    $fresh = $item->fresh();
    expect($fresh->slug)->toBe('original-slug');
    expect($fresh->label)->toBe('Renamed label');
});

// ---- destroy soft-deletes (never force) ----------------------------------

it('soft-deletes on destroy, keeping the slug resolvable for click links', function () {
    $item = PromoItem::factory()->create(['slug' => 'to-retire']);

    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->delete(route('admin.promo-items.destroy', $item))
        ->assertRedirect(route('admin.promo-items.index'))
        ->assertSessionHas('status');

    $this->assertSoftDeleted('promo_items', ['slug' => 'to-retire']);
    expect(PromoItem::withTrashed()->where('slug', 'to-retire')->exists())->toBeTrue();
});

// ---- is_active toggle is reversible --------------------------------------

it('deactivates and reactivates an item via update', function () {
    $item = PromoItem::factory()->create(['is_active' => true]);
    $admin = User::factory()->admin()->confirmed()->create();

    $this->actingAs($admin)
        ->put(route('admin.promo-items.update', $item), promoItemPayload([
            'slug' => $item->slug,
            'is_active' => false,
        ]))
        ->assertRedirect(route('admin.promo-items.index'));
    expect($item->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)
        ->put(route('admin.promo-items.update', $item), promoItemPayload([
            'slug' => $item->slug,
            'is_active' => true,
        ]))
        ->assertRedirect(route('admin.promo-items.index'));
    expect($item->fresh()->is_active)->toBeTrue();
});

// Catalog UX 2026-07-03 — images are optional (form no longer collects them),
// description is optional editorial copy capped at 500 chars.
it('creates an item without an image and with a description', function () {
    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->post(route('admin.promo-items.store'), promoItemPayload([
            'description' => 'Packs to 11 inches and shrugs off coastal gusts.',
        ]))
        ->assertRedirect(route('admin.promo-items.index'));

    $item = PromoItem::query()->where('slug', 'merino-base-layer-x')->firstOrFail();
    expect($item->image_url)->toBeNull()
        ->and($item->description)->toBe('Packs to 11 inches and shrugs off coastal gusts.');
});

it('rejects a description over 500 characters', function () {
    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->from(route('admin.promo-items.create'))
        ->post(route('admin.promo-items.store'), promoItemPayload([
            'description' => str_repeat('a', 501),
        ]))
        ->assertSessionHasErrors('description');
});
