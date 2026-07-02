# Dashboard "Send a sample" Card Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let signed-in users request the existing Reykjavik sample tripcast email from a card below their trip lists on the dashboard.

**Architecture:** A new authenticated `POST /sample/self` route on the existing `SampleController` reuses the demo-trip builder and `SampleForecast` snapshot, queuing the existing `SampleDigestMail` to the signed-in user's own email with the dashboard URL as the CTA (no magic link, no `SampleRequest` acquisition row). A per-user `RateLimiter` bucket (3/hour) rejects extras with a validation error. The Dashboard page gets an always-visible card that posts to the route via a Wayfinder action and reports success/failure with `vue-sonner` toasts.

**Tech Stack:** Laravel 13, Pest 4, Inertia v3 + Vue 3, Wayfinder, Tailwind v4.

**Spec:** `docs/superpowers/specs/2026-07-02-dashboard-sample-card-design.md`

## Global Constraints

- Copy is exact: heading **"Want to see one now?"**, body **"We'll email you a sample tripcast for Reykjavik, Iceland so you can get a preview of what your trips will look like."**, button **"Send me a sample"** (lowercase "tripcast").
- No `SampleRequest` row and no `LoginToken` (magic link) may be created by the new endpoint.
- Rate limit: 3 sends per user per hour; over-limit → validation error on key `sample`, never a 429 page.
- After modifying PHP: `vendor/bin/pint --dirty --format agent`. After modifying Vue/TS: `npm run format` and `npm run lint`.
- Run tests with `php artisan test --compact --filter=<name>`.

---

### Task 1: Authenticated sample endpoint

**Files:**
- Modify: `app/Http/Controllers/SampleController.php` (add `storeForSelf` action)
- Modify: `routes/web.php:49-67` (auth group)
- Test: `tests/Feature/Sample/DashboardSampleTest.php` (create)

**Interfaces:**
- Consumes: `SampleController::sampleTrip(array $destination, User $user): Trip` (existing private helper), `SampleForecast::forecast()`, `SampleDigestMail` (existing).
- Produces: named route `sample.self` (`POST /sample/self`, auth middleware) — Task 2's Wayfinder import depends on this exact name. Over-limit responses carry validation error key `sample`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Sample/DashboardSampleTest.php`:

```php
<?php

use App\Mail\SampleDigestMail;
use App\Models\LoginToken;
use App\Models\SampleRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->user = User::factory()->create();
    RateLimiter::clear('sample-self:'.$this->user->id);
});

it('queues the sample to the signed-in user with a dashboard CTA', function () {
    Mail::fake();

    actingAs($this->user)
        ->post(route('sample.self'))
        ->assertRedirect();

    Mail::assertQueued(SampleDigestMail::class, fn (SampleDigestMail $mail) => $mail->hasTo($this->user->email)
        && $mail->getStartedUrl === route('dashboard'));
});

it('records no acquisition row and issues no magic link', function () {
    Mail::fake();

    actingAs($this->user)->post(route('sample.self'));

    expect(SampleRequest::count())->toBe(0)
        ->and(LoginToken::count())->toBe(0);
});

it('redirects guests to login', function () {
    Mail::fake();

    post(route('sample.self'))->assertRedirect(route('login'));

    Mail::assertNothingQueued();
});

it('rejects the fourth request in an hour with a calm message', function () {
    Mail::fake();

    foreach (range(1, 3) as $attempt) {
        actingAs($this->user)->post(route('sample.self'))->assertSessionDoesntHaveErrors();
    }

    actingAs($this->user)
        ->post(route('sample.self'))
        ->assertSessionHasErrors('sample');

    Mail::assertQueuedCount(3);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=DashboardSampleTest`
Expected: FAIL — `Route [sample.self] not defined.`

- [ ] **Step 3: Add the route**

In `routes/web.php`, inside the `Route::middleware('auth')->group(...)` block, after the `settings.update` line:

```php
    // Dashboard "send a sample" card: emails the fixed demo-destination sample
    // to the signed-in user. No magic link and no SampleRequest row — those are
    // acquisition mechanics for the public /sample endpoint.
    Route::post('sample/self', [SampleController::class, 'storeForSelf'])->name('sample.self');
```

- [ ] **Step 4: Add the controller action**

In `app/Http/Controllers/SampleController.php`, add imports:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
```

Add the action after `store()` (before `sampleTrip()`), and update the class docblock's first line to mention both entry points:

```php
    /**
     * The dashboard "send a sample" action: queues the same sample digest to the
     * signed-in user's own address. No magic link (the CTA returns them to the
     * dashboard) and no SampleRequest row (that table tracks acquisition leads).
     * Per-user limiter instead of the magic-link buckets so samples can never
     * consume login-link attempts.
     */
    public function storeForSelf(Request $request, SampleForecast $sampleForecast): RedirectResponse
    {
        $user = $request->user();
        $key = 'sample-self:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'sample' => "That's a few samples already — try again in about an hour.",
            ]);
        }

        RateLimiter::hit($key, 3600);

        $trip = $this->sampleTrip(config('tripcast.sample.destination'), $user);
        $snapshot = $sampleForecast->forecast()->toArray();

        Mail::to($user->email)->queue(new SampleDigestMail($trip, $snapshot, route('dashboard')));

        return back();
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=DashboardSampleTest`
Expected: PASS (4 tests)

Also run the neighbouring sample suites to prove no regression:
Run: `php artisan test --compact --filter=Sample`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SampleController.php routes/web.php tests/Feature/Sample/DashboardSampleTest.php
git commit -m "feat(sample): authenticated sample/self endpoint for dashboard card"
```

---

### Task 2: Dashboard card UI

**Files:**
- Modify: `resources/js/pages/Dashboard.vue`

**Interfaces:**
- Consumes: named route `sample.self` from Task 1, via Wayfinder `import { self } from '@/routes/sample'` (route names `sample.store` / `sample.self` both generate into `@/routes/sample`; `Landing.vue:22` already imports `store` from there).
- Produces: nothing downstream.

- [ ] **Step 1: Regenerate Wayfinder routes**

Run: `php artisan wayfinder:generate`
Expected: exits 0; `resources/js/routes/sample/index.ts` now exports `self`.

- [ ] **Step 2: Add the card to Dashboard.vue**

In the `<script setup>` block, extend the Wayfinder imports (line 21 area) with the sample route:

```ts
import { self as sampleSelf } from '@/routes/sample';
```

Add state + handler after the `submitAdd()` function at the end of the script block:

```ts
// "Send a sample" card (spec 2026-07-02): posts to the authenticated sample
// endpoint; sent-state is per-visit only, by design.
const sampleSending = ref(false);
const sampleSent = ref(false);

function sendSample(): void {
    sampleSending.value = true;
    router.post(
        sampleSelf().url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                sampleSent.value = true;
                toast.success('Sample on its way — check your inbox.');
            },
            onError: (errors) => {
                toast.error(
                    errors.sample ??
                        "Couldn't send the sample. Please try again.",
                );
            },
            onFinish: () => {
                sampleSending.value = false;
            },
        },
    );
}
```

In the template, add the card as the last section inside `<main>`, immediately after the closing `</section>` of the Past trips block (after line 485):

```html
        <!-- "Send a sample" card: always visible, below the trip lists -->
        <section
            class="space-y-2 rounded-md border border-hairline bg-surface-raised p-5"
        >
            <h2 class="text-subtitle text-ink">Want to see one now?</h2>
            <p class="text-body text-ink-secondary">
                We'll email you a sample tripcast for Reykjavik, Iceland so you
                can get a preview of what your trips will look like.
            </p>
            <Button
                variant="outline"
                size="sm"
                class="mt-2"
                :disabled="sampleSending || sampleSent"
                @click="sendSample"
            >
                {{ sampleSent ? 'Sent — check your inbox' : 'Send me a sample' }}
            </Button>
        </section>
```

- [ ] **Step 3: Verify formatting, lint, and types**

```bash
npm run format
npm run lint
npm run types:check
```

Expected: all exit 0 (format may rewrite the edited block — that's fine).

- [ ] **Step 4: Verify the full test suite still passes**

Run: `php artisan test --compact`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Dashboard.vue resources/js/routes/
git commit -m "feat(dashboard): send-a-sample card below trip lists"
```

---

## Verification

- `php artisan test --compact --filter=Sample` — all sample suites green.
- `npm run build` (or ask the user to run `composer run dev`) so the card renders locally.
- Manual: dashboard shows the card below trips; clicking queues mail (`log` mailer in dev) and flips the button to "Sent — check your inbox"; the 4th click within an hour shows the calm error toast.
