# Catalog UX + Lighter Email Promo + Rain Profile Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make catalog items fast to add (label-first form with slug/merchant prefill, no image fields, optional description), lighten the digest email promo to a text link + description, and add a `rain` weather profile.

**Architecture:** Schema gains a nullable `description` and a nullable `image_url`; the `Promo` DTO carries description through both providers into the digest templates, which drop the thumbnail and CTA line. The form derives slug from label and merchant from URL client-side with dirty flags. `rain` fills the WeatherProfiler's warm-rain fall-through; `cold-wet` keeps its stored value and gets a display label only.

**Tech Stack:** Laravel 13, Pest 4, Inertia v3 + Vue 3, Tailwind v4.

**Spec:** `docs/superpowers/specs/2026-07-03-catalog-ux-and-lighter-promo-design.md`

## Global Constraints

- **NEVER push to `origin/main`** — every push auto-deploys to production (see `docs/deployment.md`). All work happens on branch `feat/catalog-ux-lighter-promo`.
- Stored profile value stays `cold-wet` — rename is display-only ("Cold and rainy").
- `AffiliatePromoProvider` is the frozen rollback adapter — only the `Promo` DTO type widening may touch its behavior surface; do not edit its selection logic.
- Run `vendor/bin/pint --dirty --format agent` before every commit that touches PHP.
- Tests: `php artisan test --compact` with a `--filter` or file path; run the affected file(s) per task, full suite at the end.
- PHP: strict types per file conventions (this codebase omits `declare(strict_types=1)` — match existing files), PHPDoc over inline comments, curly braces always.

---

### Task 1: Branch + schema (nullable image_url, description column, model, factory)

**Files:**
- Create: `database/migrations/<timestamp>_add_description_and_nullable_image_to_promo_items_table.php` (via artisan)
- Modify: `app/Models/PromoItem.php` (PHPDoc + `$fillable`)
- Modify: `database/factories/PromoItemFactory.php` (add `description`)
- Test: `tests/Feature/PromoItemTest.php` (append)

**Interfaces:**
- Consumes: existing `promo_items` table.
- Produces: `promo_items.description` (`VARCHAR(500) NULL`), `promo_items.image_url` nullable; `PromoItem` model with `description` fillable; factory default `'description' => null`.

- [ ] **Step 1: Create the branch**

```bash
git checkout -b feat/catalog-ux-lighter-promo
```

- [ ] **Step 2: Write the failing test**

Append to `tests/Feature/PromoItemTest.php`:

```php
// Catalog UX 2026-07-03 — images are hidden from the admin form, so the column
// must accept NULL; `description` is the optional editorial line in the email.
it('persists an item with a null image and a description', function () {
    $item = PromoItem::factory()->create([
        'image_url' => null,
        'description' => 'Packs to 11 inches and shrugs off coastal gusts.',
    ]);

    expect($item->fresh()->image_url)->toBeNull()
        ->and($item->fresh()->description)->toBe('Packs to 11 inches and shrugs off coastal gusts.');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/PromoItemTest.php`
Expected: FAIL — SQL error (column `description` doesn't exist / `image_url` NOT NULL violation).

- [ ] **Step 4: Create the migration**

```bash
php artisan make:migration add_description_and_nullable_image_to_promo_items_table --no-interaction
```

Migration content:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog UX (2026-07-03 spec): the admin form no longer collects images, so
 * `image_url` relaxes to NULL (column + data kept for later reuse); the new
 * `description` is the optional one-line editorial copy shown under the label
 * link in the digest promo unit. 500 matches the validation cap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_items', function (Blueprint $table) {
            $table->string('image_url', 2048)->nullable()->change();
            $table->string('description', 500)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('promo_items', function (Blueprint $table) {
            $table->string('image_url', 2048)->nullable(false)->change();
            $table->dropColumn('description');
        });
    }
};
```

- [ ] **Step 5: Update model and factory**

`app/Models/PromoItem.php` — in the class PHPDoc, change the `image_url` line and add `description` after `label`:

```php
 * @property string $label
 * @property string|null $description
 * @property string|null $image_url
```

In `$fillable`, add `'description'` after `'label'`:

```php
    protected $fillable = [
        'slug',
        'label',
        'description',
        'image_url',
        'url',
        'merchant',
        'weather_profile',
        'is_active',
        'featured_from',
        'featured_to',
        'sort_order',
    ];
```

`database/factories/PromoItemFactory.php` — in `definition()`, add after `'label'`:

```php
            'description' => null,
```

(Leave the factory's `image_url` default as-is — the seeder and existing tests still exercise image-bearing rows.)

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/PromoItemTest.php`
Expected: PASS (all tests in the file).

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(catalog): nullable image_url + description column"
```

---

### Task 2: Validation — image optional, description rule, payload updates

**Files:**
- Modify: `app/Http/Requests/PromoItemRequest.php:52` (rules)
- Test: `tests/Feature/Admin/PromoItemCrudTest.php`

**Interfaces:**
- Consumes: `description` column from Task 1.
- Produces: request rules `description => ['nullable','string','max:500']`, `image_url => ['nullable','string','url:https','max:2048']`. `validated()` now carries `description`.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Admin/PromoItemCrudTest.php`, update `promoItemPayload()` — remove the `'image_url'` line and add `'description' => null` after `'label'`:

```php
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
```

Append new tests (`User` is already imported at the top of the file):

```php
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
```

Keep the existing `'non-https image' => [['image_url' => 'http://placehold.co/x.png'], 'image_url']` invalid-dataset row — a *present but non-https* image must still fail.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Admin/PromoItemCrudTest.php`
Expected: the two new tests FAIL (`image_url` required / `description` not validated). Some existing tests may also fail from the payload change — that's expected until Step 3.

- [ ] **Step 3: Update the request rules**

In `app/Http/Requests/PromoItemRequest.php` `rules()`, replace the `image_url` line and add `description` after `label`:

```php
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'string', 'url:https', 'max:2048'],
```

Update the class PHPDoc's first paragraph to note: images are optional (hidden from the form since the 2026-07-03 catalog-UX spec); `description` is the optional editorial line shown in the digest.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Admin/PromoItemCrudTest.php`
Expected: PASS (whole file).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(catalog): image optional, description validated"
```

---

### Task 3: Controller passthrough + rebuilt Form.vue (label-first, prefill, no image)

**Files:**
- Modify: `app/Http/Controllers/PromoItemController.php:131-146` (`toArray`)
- Create: `resources/js/lib/weatherProfiles.ts`
- Rewrite: `resources/js/pages/Admin/Catalog/Form.vue`
- Test: `tests/Feature/Admin/PromoItemCrudTest.php` (append one)

**Interfaces:**
- Consumes: `description` from Tasks 1–2.
- Produces: `toArray()` includes `description: string|null` (Inertia `item` prop); `weatherProfileLabel(slug: string): string` + `WEATHER_PROFILE_LABELS: Record<string, string>` exported from `@/lib/weatherProfiles` (Task 6 imports these into Index.vue).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Admin/PromoItemCrudTest.php` (mirror the file's existing edit-page Inertia assertion style):

```php
it('exposes description on the edit form item prop', function () {
    $item = PromoItem::factory()->create(['description' => 'Editorial line.']);

    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->get(route('admin.promo-items.edit', $item))
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Catalog/Form')
            ->where('item.description', 'Editorial line.'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Admin/PromoItemCrudTest.php --filter="exposes description"`
Expected: FAIL — `item.description` missing.

- [ ] **Step 3: Add description to the controller projection**

In `PromoItemController::toArray()`, add after `'label'`:

```php
            'description' => $item->description,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Admin/PromoItemCrudTest.php --filter="exposes description"`
Expected: PASS.

- [ ] **Step 5: Create the shared profile-label map**

Create `resources/js/lib/weatherProfiles.ts`:

```ts
/**
 * Display labels for the fixed weather-profile taxonomy (FR-26). Stored values
 * never change (`cold-wet` stays `cold-wet` — promo rotation and the frozen
 * rollback adapter key on them); only what admins see is renamed.
 */
export const WEATHER_PROFILE_LABELS: Record<string, string> = {
    snow: 'Snow',
    hot: 'Hot',
    rain: 'Rain',
    'cold-wet': 'Cold and rainy',
    cold: 'Cold',
    mild: 'Mild (legacy)',
    'travel-essentials': 'Travel essentials',
};

export function weatherProfileLabel(slug: string): string {
    return WEATHER_PROFILE_LABELS[slug] ?? slug;
}
```

- [ ] **Step 6: Rewrite Form.vue**

Replace `resources/js/pages/Admin/Catalog/Form.vue` entirely with:

```vue
<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { weatherProfileLabel } from '@/lib/weatherProfiles';
import { index, store, update } from '@/routes/admin/promo-items';

type PromoItemRow = {
    id: number;
    slug: string;
    label: string;
    description: string | null;
    image_url: string | null;
    url: string;
    merchant: string;
    weather_profile: string;
    is_active: boolean;
    featured_from: string | null;
    featured_to: string | null;
    sort_order: number;
};

const props = defineProps<{
    item: PromoItemRow | null;
    slugLocked: boolean;
    profiles: string[];
    merchants: string[];
}>();

const isEdit = props.item !== null;

const form = useForm({
    label: props.item?.label ?? '',
    slug: props.item?.slug ?? '',
    description: props.item?.description ?? '',
    url: props.item?.url ?? '',
    merchant: props.item?.merchant ?? props.merchants[0] ?? 'amazon',
    weather_profile: props.item?.weather_profile ?? props.profiles[0] ?? '',
    is_active: props.item?.is_active ?? true,
    sort_order: props.item?.sort_order ?? 0,
    featured_from: props.item?.featured_from ?? '',
    featured_to: props.item?.featured_to ?? '',
});

// Prefill stops the moment the admin edits the derived field by hand.
const slugEdited = ref(isEdit);
const merchantEdited = ref(isEdit);

watch(
    () => form.label,
    (label) => {
        if (!slugEdited.value) {
            form.slug = slugify(label);
        }
    },
);

watch(
    () => form.url,
    (url) => {
        if (!merchantEdited.value && url.trim() !== '') {
            form.merchant = isAmazonUrl(url) ? 'amazon' : 'other';
        }
    },
);

function slugify(value: string): string {
    return value
        .toLowerCase()
        .replace(/['’]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function isAmazonUrl(value: string): boolean {
    try {
        return /(^|\.)amazon\./.test(new URL(value).hostname);
    } catch {
        return false;
    }
}

function submit(): void {
    form.clearErrors();

    if (isEdit && props.item) {
        form.put(update(props.item.id).url);

        return;
    }

    form.post(store().url);
}
</script>

<template>
    <Head :title="isEdit ? 'Admin — edit item' : 'Admin — new item'" />

    <main class="mx-auto flex max-w-2xl flex-col gap-8 px-6 py-12">
        <div class="space-y-1">
            <h1 class="text-title text-ink">
                {{ isEdit ? 'Edit item' : 'New item' }}
            </h1>
            <p class="text-body text-ink-secondary">
                A sponsored product served in the digest promo slot.
            </p>
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <div class="space-y-2">
                <Label for="label">Title</Label>
                <Input id="label" v-model="form.label" autocomplete="off" />
                <InputError :message="form.errors.label" />
            </div>

            <div class="space-y-2">
                <Label for="slug">Slug</Label>
                <Input
                    id="slug"
                    v-model="form.slug"
                    :disabled="slugLocked"
                    autocomplete="off"
                    @input="slugEdited = true"
                />
                <p class="text-meta text-ink-secondary">
                    <template v-if="slugLocked">
                        The slug is the permanent attribution key — it can't
                        change once set.
                    </template>
                    <template v-else>
                        Prefilled from the title. Becomes permanent once saved.
                    </template>
                </p>
                <InputError :message="form.errors.slug" />
            </div>

            <div class="space-y-2">
                <Label for="description">Description</Label>
                <textarea
                    id="description"
                    v-model="form.description"
                    rows="2"
                    maxlength="500"
                    class="w-full rounded-sm border border-hairline bg-surface-raised px-3 py-2 text-body text-ink focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    placeholder="One quiet line on why it earns its bag space (optional)"
                ></textarea>
                <InputError :message="form.errors.description" />
            </div>

            <div class="space-y-2">
                <Label for="url">Product URL</Label>
                <Input
                    id="url"
                    v-model="form.url"
                    type="url"
                    placeholder="https://…"
                />
                <p class="text-meta text-ink-secondary">
                    Amazon links get the associate tag appended at send; other
                    merchants are used verbatim.
                </p>
                <InputError :message="form.errors.url" />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="merchant">Merchant</Label>
                    <select
                        id="merchant"
                        v-model="form.merchant"
                        class="h-10 w-full rounded-sm border border-hairline bg-surface-raised px-3 text-body text-ink focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @change="merchantEdited = true"
                    >
                        <option v-for="m in merchants" :key="m" :value="m">
                            {{ m }}
                        </option>
                    </select>
                    <p class="text-meta text-ink-secondary">
                        Set automatically from the product URL.
                    </p>
                    <InputError :message="form.errors.merchant" />
                </div>

                <div class="space-y-2">
                    <Label for="weather_profile">Weather profile</Label>
                    <select
                        id="weather_profile"
                        v-model="form.weather_profile"
                        class="h-10 w-full rounded-sm border border-hairline bg-surface-raised px-3 text-body text-ink focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <option v-for="p in profiles" :key="p" :value="p">
                            {{ weatherProfileLabel(p) }}
                        </option>
                    </select>
                    <InputError :message="form.errors.weather_profile" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="featured_from">Featured from</Label>
                    <Input
                        id="featured_from"
                        v-model="form.featured_from"
                        type="date"
                    />
                    <InputError :message="form.errors.featured_from" />
                </div>

                <div class="space-y-2">
                    <Label for="featured_to">Featured to</Label>
                    <Input
                        id="featured_to"
                        v-model="form.featured_to"
                        type="date"
                    />
                    <p class="text-meta text-ink-secondary">
                        Leave blank to pin indefinitely.
                    </p>
                    <InputError :message="form.errors.featured_to" />
                </div>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="sort_order">Sort order</Label>
                    <Input
                        id="sort_order"
                        v-model="form.sort_order"
                        type="number"
                        min="0"
                    />
                    <InputError :message="form.errors.sort_order" />
                </div>

                <div class="space-y-2">
                    <Label for="is_active">Status</Label>
                    <label
                        class="flex h-10 items-center gap-2 text-body text-ink"
                    >
                        <input
                            id="is_active"
                            v-model="form.is_active"
                            type="checkbox"
                            class="size-4 rounded-sm border-hairline text-brand focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        Active (shown in digests)
                    </label>
                    <InputError :message="form.errors.is_active" />
                </div>
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ isEdit ? 'Save item' : 'Add item' }}
                </Button>
                <Button as-child variant="ghost" type="button">
                    <Link :href="index().url">Cancel</Link>
                </Button>
            </div>
        </form>
    </main>
</template>
```

Notes for the implementer:
- The form no longer posts `image_url` at all — the request rule is `nullable`, so its absence validates.
- `@input` on the slug Input marks it hand-edited; the Label watcher then leaves it alone. On edit pages both flags start `true`.
- Description posts `''` when blank; Laravel's `ConvertEmptyStringsToNull` middleware turns that into `null` before validation.

- [ ] **Step 7: Build + lint**

Run: `npm run build` — expected: builds clean (fixes any `Unable to locate file in Vite manifest` risk).
Run: `npx eslint resources/js/pages/Admin/Catalog/Form.vue resources/js/lib/weatherProfiles.ts --fix` — expected: no errors.
Run the whole CRUD file once more: `php artisan test --compact tests/Feature/Admin/PromoItemCrudTest.php` — expected: PASS.

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(catalog): label-first form, slug/merchant prefill, description, images hidden"
```

---

### Task 4: Rain profile (model constant + profiler mapping)

**Files:**
- Modify: `app/Models/PromoItem.php:54-78` (constants)
- Modify: `app/Services/Promo/WeatherProfiler.php:70-75` (match)
- Test: `tests/Feature/Promo/WeatherProfilerTest.php`, `tests/Feature/Admin/PromoItemCrudTest.php` (append)

**Interfaces:**
- Consumes: nothing new.
- Produces: `PromoItem::PROFILE_RAIN = 'rain'` in `PROFILES`; `WeatherProfiler::profile()` returns `'rain'` for wet-and-not-cold forecasts.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Promo/WeatherProfilerTest.php`:

```php
// 2026-07-03 spec — warm rain (precip ≥ 50, avg high ≥ 60°F) used to fall
// through to Essentials; it now routes to `rain`. Cold rain keeps `cold-wet`,
// and hot still outranks rain.
it('maps warm rain to rain, keeps cold rain on cold-wet, and lets hot outrank rain', function () {
    expect($this->profiler->profile(profSnap([profDay(65, 70, 'Rain'), profDay(68, 60, 'Rain')])))->toBe('rain')
        ->and($this->profiler->profile(profSnap([profDay(58, 70, 'Rain'), profDay(60, 60, 'Rain')])))->toBe('cold-wet') // avg 59 < 60
        ->and($this->profiler->profile(profSnap([profDay(60, 70, 'Rain'), profDay(60, 60, 'Rain')])))->toBe('rain') // avg exactly 60
        ->and($this->profiler->profile(profSnap([profDay(85, 70, 'Rain'), profDay(88, 60, 'Rain')])))->toBe('hot');
});
```

Append to `tests/Feature/Admin/PromoItemCrudTest.php`:

```php
it('accepts the rain weather profile on create', function () {
    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->post(route('admin.promo-items.store'), promoItemPayload([
            'weather_profile' => PromoItem::PROFILE_RAIN,
        ]))
        ->assertRedirect(route('admin.promo-items.index'));

    expect(PromoItem::query()->where('slug', 'merino-base-layer-x')->firstOrFail()->weather_profile)
        ->toBe('rain');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Promo/WeatherProfilerTest.php` — expected: new test FAILS (`null` returned for warm rain).
Run: `php artisan test --compact tests/Feature/Admin/PromoItemCrudTest.php --filter="rain weather profile"` — expected: FAIL (undefined constant `PROFILE_RAIN`).

- [ ] **Step 3: Add the constant and taxonomy entry**

In `app/Models/PromoItem.php`, add after `PROFILE_HOT`:

```php
    public const PROFILE_RAIN = 'rain';
```

And in `PROFILES`, insert after `self::PROFILE_HOT,`:

```php
        self::PROFILE_RAIN,
```

Update the migration-style comment on the model PHPDoc if it enumerates profiles (it says "fixed weather taxonomy" — add `rain` wherever the list is spelled out, e.g. the `weather_profile` property comment in the create-table migration stays untouched; historical migrations are never edited).

- [ ] **Step 4: Add the profiler arm**

In `app/Services/Promo/WeatherProfiler.php`, replace the `match` with:

```php
        return match (true) {
            $avgHigh >= self::HOT_HIGH => 'hot',
            ($maxPrecip ?? 0) >= self::WET_PRECIP && $avgHigh < self::WET_HIGH => 'cold-wet',
            ($maxPrecip ?? 0) >= self::WET_PRECIP => 'rain',
            $avgHigh < self::COLD_HIGH => 'cold',
            default => null, // neutral (mild) → Essentials
        };
```

Update the class PHPDoc's profile list to `snow/hot/rain/cold-wet/cold` and note that `rain` is the wet-but-not-cold band (precip ≥ 50, avg high 60–79°F) that previously fell through to Essentials.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Promo/WeatherProfilerTest.php tests/Feature/Admin/PromoItemCrudTest.php`
Expected: PASS (both files, including all pre-existing tests — the old "neutral" test at 60/65°F uses precip 10, unaffected).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(promo): rain weather profile for warm-rain forecasts"
```

---

### Task 5: Promo DTO description + provider passthrough

**Files:**
- Modify: `app/Services/Promo/Promo.php`
- Modify: `app/Services/Promo/DatabasePromoProvider.php:96-106` (`toPromo`)
- Test: `tests/Feature/Promo/DatabasePromoProviderTest.php` (append)

**Interfaces:**
- Consumes: `PromoItem->description` (Task 1).
- Produces: `Promo` DTO: `new Promo(slug, label, ?imageUrl, url, ?description = null)` — positional order unchanged for existing call sites; `imageUrl` now nullable. Task 6's templates read `$promo->description`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Promo/DatabasePromoProviderTest.php`:

```php
// 2026-07-03 spec — the email unit is now label + optional description, no
// image. The DTO must carry description through and tolerate a null image.
it('passes description through and tolerates a null image', function () {
    PromoItem::factory()->essentials()->create([
        'slug' => 'desc-item',
        'image_url' => null,
        'description' => 'Earns its bag space.',
    ]);

    $promo = $this->provider->select(promoSnap(promoMildDays()), '2026-07-03');

    expect($promo->slug)->toBe('desc-item')
        ->and($promo->description)->toBe('Earns its bag space.')
        ->and($promo->imageUrl)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Promo/DatabasePromoProviderTest.php --filter="passes description"`
Expected: FAIL — `Promo::__construct(): Argument #3 ($imageUrl) must be of type string, null given` (or missing `description` property).

- [ ] **Step 3: Widen the DTO and pass description through**

Replace `app/Services/Promo/Promo.php`:

```php
<?php

namespace App\Services\Promo;

/**
 * A selected affiliate promo unit (AD-18). The `slug` is the stable attribution
 * key (promo_events, Story 5.4); `url` is the tagged Amazon URL. `imageUrl` is
 * legacy-nullable (the admin form stopped collecting images, 2026-07-03 spec)
 * and `description` is the optional one-line editorial copy under the label.
 */
final class Promo
{
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly ?string $imageUrl,
        public readonly string $url,
        public readonly ?string $description = null,
    ) {}
}
```

In `DatabasePromoProvider::toPromo()`, add after `label:`:

```php
            description: $item->description,
```

(`AffiliatePromoProvider` needs no edit — its named-argument constructions omit `description`, which defaults to `null`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Promo/DatabasePromoProviderTest.php tests/Feature/Promo/AffiliatePromoProviderTest.php`
Expected: PASS (both files).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(promo): Promo DTO carries description, image nullable"
```

---

### Task 6: Lighter email unit — drop image + CTA, render description

**Files:**
- Modify: `resources/views/emails/digest.blade.php:71-97`
- Modify: `resources/views/emails/digest-text.blade.php:18-24`
- Modify: `app/Mail/DigestMail.php:96` (remove `promoCta`)
- Modify: `config/tripcast.php:165-166` (remove `cta`)
- Test: `tests/Feature/Digest/DigestMailTest.php:434-463`

**Interfaces:**
- Consumes: `Promo->description` (Task 5).
- Produces: promo unit = Sponsored kicker → label link → optional description → disclosure. `promoCta` variable and `tripcast.promo.cta` config key no longer exist.

- [ ] **Step 1: Rewrite the promo mail tests (failing first)**

In `tests/Feature/Digest/DigestMailTest.php`, replace the test at lines 436–456 with:

```php
// Story 5.3/5.4 + 2026-07-03 spec — the promo unit is a text link (label) with
// an optional description: no thumbnail, no CTA line, so it reads editorial
// rather than banner. The link is the SIGNED redirect, never a raw affiliate
// URL in the body (FR-18); the Sponsored kicker + Amazon disclosure stay.
it('renders the lighter promo unit with a description and a signed redirect link', function () {
    $promo = new Promo(
        'packing-cubes',
        'Compression packing cubes',
        null,
        'https://www.amazon.com/dp/X?tag=mytag-99',
        'Halves the bulk of a week of layers.',
    );
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29', null, $promo);

    $mail->assertSeeInHtml('Sponsored');
    $mail->assertSeeInHtml('Compression packing cubes');
    $mail->assertSeeInHtml('Halves the bulk of a week of layers.');
    $mail->assertSeeInHtml('As an Amazon Associate, tripcast earns from qualifying purchases');
    $mail->assertSeeInText('Sponsored');
    $mail->assertSeeInText('Compression packing cubes');
    $mail->assertSeeInText('Halves the bulk of a week of layers.');
    $mail->assertSeeInText('As an Amazon Associate, tripcast earns from qualifying purchases');

    $html = $mail->render();
    expect($html)->toContain('email/promo/')
        ->and($html)->toContain('signature=')
        ->and($html)->not->toContain('amazon.com')
        ->and($html)->not->toContain('View price');
});

it('omits the description line when the promo has none', function () {
    $promo = new Promo('packing-cubes', 'Compression packing cubes', null, 'https://www.amazon.com/dp/X?tag=t');
    $mail = new DigestMail(digestTrip(), digestSnapshot(), '2026-06-29', null, $promo);

    $mail->assertSeeInHtml('Compression packing cubes');
    expect($mail->render())->not->toContain('tc-promo-desc');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Digest/DigestMailTest.php --filter="promo"`
Expected: new tests FAIL (description not rendered; `tc-promo-desc` marker absent is trivially true but description assertion fails).

- [ ] **Step 3: Rewrite the HTML promo block**

In `resources/views/emails/digest.blade.php`, replace lines 71–97 (the whole `@if ($promo)` block and its comment) with:

```blade
                            {{-- Affiliate promo slot (Epic 5, AD-18/UX-DR12) — one quiet text unit
                                 below the forecast: "Sponsored" kicker, label link, optional
                                 description, disclosure. No image, no CTA (2026-07-03 spec):
                                 editorial, not banner. --}}
                            @if ($promo)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:36px 0 0; border-top:1px solid #E3EAF1;">
                                    <tr>
                                        <td style="padding:20px 0 28px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                            <p class="tc-ink-secondary" style="margin:0 0 6px; font-size:11px; line-height:16px; letter-spacing:0.08em; text-transform:uppercase; color:#9FB0BF;">Sponsored</p>
                                            <a href="{{ $promoUrl }}" class="tc-ink" style="display:inline-block; font-size:15px; line-height:20px; font-weight:600; color:#2563A6; text-decoration:none;">{{ $promo->label }}</a>
                                            @if ($promo->description)
                                                <p class="tc-ink-secondary tc-promo-desc" style="margin:4px 0 0; font-size:14px; line-height:20px; color:#51616E;">{{ $promo->description }}</p>
                                            @endif
                                            <p class="tc-ink-secondary" style="margin:12px 0 0; font-size:12px; line-height:18px; color:#9FB0BF;">As an Amazon Associate, tripcast earns from qualifying purchases</p>
                                        </td>
                                    </tr>
                                </table>
                            @endif
```

(The label link takes the brand-blue link color `#2563A6` so it's recognizably clickable now that it's the only click target.)

- [ ] **Step 4: Rewrite the text promo block and drop promoCta**

In `resources/views/emails/digest-text.blade.php`, replace lines 18–24 with:

```blade
@if ($promo)

Sponsored
{{ $promo->label }}
@if ($promo->description)
{{ $promo->description }}
@endif
{{ $promoUrl }}
As an Amazon Associate, tripcast earns from qualifying purchases
@endif
```

In `app/Mail/DigestMail.php`, delete line 96:

```php
                'promoCta' => (string) config('tripcast.promo.cta'),
```

In `config/tripcast.php`, delete the `cta` entry and its comment (lines 165–166):

```php
        // Call-to-action label on the promo link (A/B this later — e.g. "Buy now").
        'cta' => env('TRIPCAST_PROMO_CTA', 'View price'),
```

Then confirm nothing else references it:

Run: `grep -rn "promoCta\|promo.cta\|TRIPCAST_PROMO_CTA" app config resources tests`
Expected: no matches.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Digest/DigestMailTest.php`
Expected: PASS (whole file).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(digest): lighter promo unit — text link + description, no image/CTA"
```

---

### Task 7: Index profile labels + full verification

**Files:**
- Modify: `resources/js/pages/Admin/Catalog/Index.vue:117-119` (+ script import)
- Test: full suite + build

**Interfaces:**
- Consumes: `weatherProfileLabel` from `@/lib/weatherProfiles` (Task 3).
- Produces: Index "Profile" column shows display labels ("Cold and rainy", "Rain", …).

- [ ] **Step 1: Use the display label in the Index table**

In `resources/js/pages/Admin/Catalog/Index.vue`, add to the script-setup imports:

```ts
import { weatherProfileLabel } from '@/lib/weatherProfiles';
```

Replace the Profile cell (line 118):

```html
                        <td class="px-4 py-2 text-ink-secondary">
                            {{ weatherProfileLabel(item.weather_profile) }}
                        </td>
```

Also update the `PromoItemRow`-equivalent type in Index.vue (its local item type at the top of the script) — change `image_url: string;` to `image_url: string | null;` and add `description: string | null;` so it matches the controller projection.

- [ ] **Step 2: Lint + build**

Run: `npx eslint resources/js/pages/Admin/Catalog/Index.vue --fix` — expected: clean.
Run: `npm run build` — expected: builds clean.

- [ ] **Step 3: Full test suite**

Run: `php artisan test --compact`
Expected: PASS, no regressions (watch `PromoItemSeederTest` — untouched columns, should pass; and `CatalogPerformanceTest`).

Run: `vendor/bin/pint --dirty --format agent` — expected: no changes or auto-fixed, re-commit if it edits.

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat(catalog): display labels for weather profiles in admin list"
```

---

### Task 8: Finish the branch

- [ ] **Step 1:** Use the superpowers:finishing-a-development-branch skill — present merge/PR options to the user. **Do not push to `origin/main` without explicit user sign-off** (auto-deploys to prod; a schema migration ships with this — re-read `docs/deployment.md` first).
