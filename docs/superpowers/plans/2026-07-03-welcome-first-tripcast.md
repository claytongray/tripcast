# Welcome + First Tripcast Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a new user's trip is already inside the forecast window, their welcome email carries their first real tripcast immediately; when it's outside the window, they get the welcome heads-up plus an offer to see a sample now.

**Architecture:** One decision seam — `SendWelcomeEmail` — branches on `CadencePredicate::isDue`. In-window dispatches the existing `SendTripDigest` job in a new "welcome mode" (same claim → fetch → snapshot → render → retry path, just a welcome intro block + welcome subject), which claims today's `(trip_id, send_date)` slot so the 7am job skips the trip. Out-of-window queues the existing `WelcomeMail`, now carrying a signed sample CTA that reuses the generic Reykjavik sample.

**Tech Stack:** Laravel 13, PHP 8.3, Pest 4, queued Mailables/Jobs, Blade email templates.

## Global Constraints

- Forecast window (single authority `App\Digest\CadencePredicate`): `departure_date − tripcast.forecast.horizon_days (default 7) ≤ D ≤ return_date`, D = `now('America/New_York')` calendar date. Never re-derive this math.
- Dedup key is the `email_logs` unique `(trip_id, send_date)` index; `send_date` string is always `now('America/New_York')->toDateString()`.
- Confirmed-first (AD-6): the welcome path only fires for a confirmed owner (at trip creation for a logged-in user, at magic-link confirmation for a new signup). Do not change this gate.
- Exactly one tripcast per trip per calendar day. The immediate welcome-mode send claims the slot; the 7am job must see it and skip.
- Run `vendor/bin/pint --dirty --format agent` before each commit that touches PHP.
- Tests: Pest feature tests. Run with `php artisan test --compact --filter=<name>`.

---

### Task 1: DigestMail welcome mode (subject + intro block)

Give `DigestMail` an optional welcome mode: a welcome-flavored subject and a welcome intro block rendered above the normal countdown/forecast. Forecast rows, footer, and legal are untouched.

**Files:**
- Modify: `app/Mail/DigestMail.php` (constructor line 44-56, `envelope()` line 58-66, `content()` `with` array line 73-109)
- Modify: `resources/views/emails/digest.blade.php` (insert after line 26 `<td class="tc-card" ...>`)
- Modify: `resources/views/emails/digest-text.blade.php` (insert at top, before line 1)
- Test: `tests/Feature/Digest/DigestMailTest.php`

**Interfaces:**
- Produces: `new DigestMail(Trip $trip, array $snapshot, string $sendDate, ?string $narration = null, ?Promo $promo = null, bool $welcome = false)` — the new trailing `bool $welcome` param (default false keeps every existing caller unchanged). Welcome mode subject: `"You're all set for {placeShort}"`. Welcome mode body contains the intro line `Here's your first forecast` plus the normal forecast rows.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Digest/DigestMailTest.php` (reuse whatever `snapshot()`/trip helpers the file already defines; if it builds a `DigestMail` via a local helper, pass `welcome: true` through it):

```php
it('renders welcome mode with a welcome subject and intro above the forecast', function () {
    $trip = User::factory()->confirmed()->create()->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533, 'longitude' => -3.1883,
        'departure_date' => '2026-07-03', 'return_date' => '2026-07-10',
        'status' => Trip::STATUS_ACTIVE,
    ]);
    $snapshot = [
        'days' => [
            ['date' => '2026-07-03', 'conditionText' => 'Sunny', 'emoji' => '☀️', 'precipChance' => 10,
             'highC' => 20.0, 'lowC' => 12.0, 'highF' => 68.0, 'lowF' => 53.6, 'humidity' => 50, 'feelsLikeC' => 20.0, 'feelsLikeF' => 68.0],
        ],
        'limited' => false,
    ];

    $mail = new DigestMail($trip, $snapshot, '2026-07-03', welcome: true);

    expect($mail->envelope()->subject)->toBe("You're all set for Edinburgh");
    $rendered = $mail->render();
    expect($rendered)->toContain('first forecast')  // welcome intro present
        ->and($rendered)->toContain('Edinburgh');   // forecast still renders
});

it('omits the welcome intro in normal mode', function () {
    $trip = User::factory()->confirmed()->create()->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533, 'longitude' => -3.1883,
        'departure_date' => '2026-07-03', 'return_date' => '2026-07-10',
        'status' => Trip::STATUS_ACTIVE,
    ]);
    $snapshot = ['days' => [['date' => '2026-07-03', 'conditionText' => 'Sunny', 'emoji' => '☀️', 'precipChance' => 10,
        'highC' => 20.0, 'lowC' => 12.0, 'highF' => 68.0, 'lowF' => 53.6, 'humidity' => 50, 'feelsLikeC' => 20.0, 'feelsLikeF' => 68.0]], 'limited' => false];

    $mail = new DigestMail($trip, $snapshot, '2026-07-03');

    expect($mail->render())->not->toContain('first forecast');
});
```

Ensure the `use` block at the top of the file has `App\Mail\DigestMail`, `App\Models\Trip`, `App\Models\User` (add any missing).

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=DigestMailTest`
Expected: FAIL — welcome mode subject/intro not implemented (the `welcome:` named arg errors or the intro assertion fails).

- [ ] **Step 3: Add the `welcome` param and welcome subject in `DigestMail.php`**

Add the trailing constructor param (line 44-50):

```php
    public function __construct(
        public Trip $trip,
        public array $snapshot,
        string $sendDate,
        public ?string $narration = null,
        public ?Promo $promo = null,
        public bool $welcome = false,
    ) {
```

Replace `envelope()` (line 58-66) with:

```php
    public function envelope(): Envelope
    {
        if ($this->welcome) {
            // Welcome mode (first tripcast delivered inside the welcome): lead with
            // the calm "you're all set" line, not the countdown.
            return new Envelope(
                subject: "You're all set for ".$this->countdown->placeShort($this->trip),
            );
        }

        // Place leads, countdown is the hook, the weather verdict never appears
        // in the subject (UX-DR16): "Edinburgh — 5 days to go".
        return new Envelope(
            subject: $this->countdown->placeShort($this->trip)
                .' — '.$this->countdown->subjectSuffix($this->trip, $this->today),
        );
    }
```

Add `'welcome' => $this->welcome,` to the `with` array in `content()` (place it near the top of the array, right after `'placeShort' => ...` on line 78):

```php
                'placeShort' => $this->countdown->placeShort($this->trip),
                // Welcome mode renders a short intro block above the countdown.
                'welcome' => $this->welcome,
```

- [ ] **Step 4: Add the welcome intro to `resources/views/emails/digest.blade.php`**

Insert immediately after `<td class="tc-card" ...>` (line 26), before the `{{-- Header ... --}}` comment on line 28:

```blade
                            @if ($welcome)
                                {{-- Welcome mode: the first tripcast arrives inside the welcome. --}}
                                <p class="tc-ink" style="margin:0 0 6px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:22px; line-height:30px; font-weight:600; color:#16202B;">
                                    You're all set for {{ $placeShort }}
                                </p>
                                <p class="tc-ink-secondary" style="margin:0 0 28px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:26px; color:#51616E;">
                                    Your tripcast for {{ $place }} is set — {{ $dateRange }}. Here's your first forecast:
                                </p>
                            @endif
```

- [ ] **Step 5: Add the welcome intro to `resources/views/emails/digest-text.blade.php`**

Insert at the very top of the file, before the existing line 1 `{{ $placeShort }}`:

```blade
@if ($welcome)
You're all set for {{ $placeShort }}

Your tripcast for {{ $place }} is set — {{ $dateRange }}. Here's your first forecast:

@endif
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=DigestMailTest`
Expected: PASS (all DigestMail tests, including the two new ones).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mail/DigestMail.php resources/views/emails/digest.blade.php resources/views/emails/digest-text.blade.php tests/Feature/Digest/DigestMailTest.php
git commit -m "feat(digest): add welcome mode to DigestMail"
```

---

### Task 2: Thread welcome mode through SendTripDigest

The send job gains an optional `welcome` flag that it passes into `DigestMail`. All claim/fetch/snapshot/retry behavior is unchanged.

**Files:**
- Modify: `app/Jobs/SendTripDigest.php` (constructor line 42-45, `deliver()` `new DigestMail(...)` line 195-197)
- Test: `tests/Feature/Digest/SendTripDigestTest.php`

**Interfaces:**
- Consumes: `DigestMail`'s trailing `bool $welcome` param (Task 1).
- Produces: `new SendTripDigest(Trip $trip, string $sendDate, bool $welcome = false)` — trailing `bool $welcome`, default false. When true, the delivered `DigestMail` has `->welcome === true`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Digest/SendTripDigestTest.php` (the file already defines `sendTrip()`, `sampleForecast()`, `runSendJob()`; add a welcome-aware runner inline):

```php
it('delivers the digest in welcome mode when the welcome flag is set', function () {
    Mail::fake();
    $trip = sendTrip();
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(sampleForecast());

    (new SendTripDigest($trip, '2026-06-29', welcome: true))->handle($weather);

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->welcome === true && $m->hasTo($trip->user->email));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=SendTripDigestTest`
Expected: FAIL — `welcome:` named arg not accepted / `$m->welcome` undefined.

- [ ] **Step 3: Add the `welcome` flag to `SendTripDigest.php`**

Constructor (line 42-45):

```php
    public function __construct(
        public Trip $trip,
        public string $sendDate,
        public bool $welcome = false,
    ) {}
```

In `deliver()`, pass it into the Mailable (line 195-197):

```php
                Mail::to($this->trip->user->email)->send(
                    new DigestMail($this->trip, $snapshot, $this->sendDate, $narration, $promo, $this->welcome),
                );
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=SendTripDigestTest`
Expected: PASS (existing tests plus the new welcome-mode one).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs/SendTripDigest.php tests/Feature/Digest/SendTripDigestTest.php
git commit -m "feat(digest): thread welcome mode through SendTripDigest"
```

---

### Task 3: Branch SendWelcomeEmail on the forecast window

`SendWelcomeEmail` becomes the single decision point: in-window → dispatch the welcome-mode tripcast (claiming today's slot); out-of-window → queue the heads-up `WelcomeMail`. Opt-out still short-circuits to nothing.

**Files:**
- Modify: `app/Actions/SendWelcomeEmail.php` (whole file)
- Test: `tests/Feature/Mail/SendWelcomeEmailTest.php` (create)

**Interfaces:**
- Consumes: `App\Digest\CadencePredicate::isDue(Trip, CarbonInterface): bool`; `SendTripDigest(Trip, string $sendDate, bool $welcome)` (Task 2).
- Produces: `SendWelcomeEmail::handle(Trip $trip): void` — unchanged signature; both existing callers (`CreateTrip`, `MagicLinkController::consume`) keep calling it as-is.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mail/SendWelcomeEmailTest.php`:

```php
<?php

use App\Actions\SendWelcomeEmail;
use App\Jobs\SendTripDigest;
use App\Mail\WelcomeMail;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:05:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function welcomeTrip(string $departure, string $return): Trip
{
    return User::factory()->confirmed()->create()->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533, 'longitude' => -3.1883,
        'departure_date' => $departure, 'return_date' => $return,
        'status' => Trip::STATUS_ACTIVE,
    ]);
}

it('dispatches a welcome-mode tripcast for an in-window trip', function () {
    Bus::fake();
    Mail::fake();
    // horizon 7; today 2026-06-29 ET → window opens 2026-06-26 for a 2026-07-03 departure.
    $trip = welcomeTrip('2026-07-03', '2026-07-10');

    app(SendWelcomeEmail::class)->handle($trip);

    Bus::assertDispatched(SendTripDigest::class, fn (SendTripDigest $j) => $j->trip->is($trip)
        && $j->sendDate === '2026-06-29'
        && $j->welcome === true);
    Mail::assertNotQueued(WelcomeMail::class);
});

it('queues the heads-up welcome for an out-of-window trip', function () {
    Bus::fake();
    Mail::fake();
    // Departure far out: window opens 2026-08-24, today is 2026-06-29 → out of window.
    $trip = welcomeTrip('2026-08-31', '2026-09-07');

    app(SendWelcomeEmail::class)->handle($trip);

    Mail::assertQueued(WelcomeMail::class, fn (WelcomeMail $m) => $m->trip->is($trip));
    Bus::assertNotDispatched(SendTripDigest::class);
});

it('sends nothing when the owner has opted out', function () {
    Bus::fake();
    Mail::fake();
    $trip = welcomeTrip('2026-07-03', '2026-07-10');
    $trip->user->update(['email_opted_out' => true]);

    app(SendWelcomeEmail::class)->handle($trip);

    Bus::assertNotDispatched(SendTripDigest::class);
    Mail::assertNothingQueued();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=SendWelcomeEmailTest`
Expected: FAIL — current action always queues `WelcomeMail`; the in-window test finds no dispatched `SendTripDigest`.

- [ ] **Step 3: Rewrite `app/Actions/SendWelcomeEmail.php`**

```php
<?php

namespace App\Actions;

use App\Digest\CadencePredicate;
use App\Jobs\SendTripDigest;
use App\Mail\WelcomeMail;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * The single welcome decision point (FR-9), honoring account-level opt-out
 * (AD-13). Fired when a trip becomes real-for-sending: at creation for an
 * already-confirmed owner, or at email confirmation for a new signup.
 *
 * If the trip is already inside the Forecast Window today, the welcome IS the
 * first tripcast: dispatch the send job in welcome mode, which claims today's
 * (trip_id, send_date) slot — so the 7am run skips this trip and the traveller
 * gets value immediately instead of waiting. Otherwise send the calm heads-up
 * welcome (with its sample offer) and let the daily cadence begin on schedule.
 */
class SendWelcomeEmail
{
    public function __construct(private CadencePredicate $cadence) {}

    public function handle(Trip $trip): void
    {
        if ($trip->user->email_opted_out) {
            return;
        }

        $today = CarbonImmutable::now('America/New_York');

        if ($this->cadence->isDue($trip, $today)) {
            SendTripDigest::dispatch($trip, $today->toDateString(), welcome: true);

            return;
        }

        Mail::to($trip->user->email)->queue(new WelcomeMail($trip));
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=SendWelcomeEmailTest`
Expected: PASS (all three).

- [ ] **Step 5: Run the welcome + trip suites to catch regressions**

Run: `php artisan test --compact --filter="WelcomeMail|AddTrip|MagicLink"`
Expected: PASS. (These exercise the callers of `SendWelcomeEmail`; an in-window factory trip now dispatches a job instead of queuing `WelcomeMail`. If an existing test asserted `WelcomeMail` was queued for a trip that happens to be in-window, update it to either use an out-of-window trip or assert the dispatched `SendTripDigest` — keep the assertion matching the intent of that test.)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/SendWelcomeEmail.php tests/Feature/Mail/SendWelcomeEmailTest.php
git commit -m "feat(welcome): send first tripcast immediately for in-window trips"
```

---

### Task 4: Out-of-window sample CTA in the welcome email

The heads-up `WelcomeMail` gains a "see a sample now" CTA. It's a signed GET link (same pattern as the digest's end-trip/unsubscribe links) that queues the existing generic Reykjavik sample to the trip's owner, then shows a small confirmation page.

**Files:**
- Modify: `routes/web.php` (near the existing `sample.store` route, line 32)
- Modify: `app/Http/Controllers/SampleController.php` (add `sendFromWelcome` method; reuse private `sampleTrip`)
- Create: `resources/views/sample-sent.blade.php`
- Modify: `app/Mail/WelcomeMail.php` (`content()` `with` array line 38-45)
- Modify: `resources/views/emails/welcome.blade.php` (add CTA after the intro paragraph, line 32)
- Modify: `resources/views/emails/welcome-text.blade.php` (add CTA line after line 3)
- Test: `tests/Feature/Sample/WelcomeSampleCtaTest.php` (create); `tests/Feature/Mail/WelcomeMailTest.php` (extend)

**Interfaces:**
- Consumes: `App\Services\Sample\SampleForecast::forecast(): Forecast`; `App\Mail\SampleDigestMail(Trip $trip, array $snapshot, string $ctaUrl)`; `SampleController::sampleTrip(array $destination, User $user): Trip` (existing private helper).
- Produces: signed route `email.sample.send` (GET `sample/from-welcome/{user}`); `WelcomeMail` view var `sampleUrl` (string, the signed route).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Sample/WelcomeSampleCtaTest.php`:

```php
<?php

use App\Mail\SampleDigestMail;
use App\Models\SampleRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

it('queues the generic sample to the user from a valid signed welcome link', function () {
    Mail::fake();
    $user = User::factory()->confirmed()->create();

    $url = URL::signedRoute('email.sample.send', ['user' => $user->id]);
    $this->get($url)->assertOk();

    Mail::assertQueued(SampleDigestMail::class, fn (SampleDigestMail $m) => $m->hasTo($user->email));
    expect(SampleRequest::where('user_id', $user->id)->where('source', SampleRequest::SOURCE_LANDING)->exists())->toBeTrue();
});

it('rejects an unsigned or tampered welcome sample link', function () {
    Mail::fake();
    $user = User::factory()->confirmed()->create();

    $this->get(route('email.sample.send', ['user' => $user->id]))->assertForbidden();

    Mail::assertNothingQueued();
});
```

- [ ] **Step 2: Write the failing test for the WelcomeMail CTA**

Add to `tests/Feature/Mail/WelcomeMailTest.php` (add `use App\Mail\WelcomeMail;` etc. if not present):

```php
it('includes a signed sample CTA in the heads-up welcome', function () {
    $trip = User::factory()->confirmed()->create()->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533, 'longitude' => -3.1883,
        'departure_date' => '2026-08-31', 'return_date' => '2026-09-07',
        'status' => \App\Models\Trip::STATUS_ACTIVE,
    ]);

    $rendered = (new WelcomeMail($trip))->render();

    expect($rendered)->toContain('/sample/from-welcome/'.$trip->user->id)
        ->and($rendered)->toContain('sample');
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="WelcomeSampleCtaTest|WelcomeMailTest"`
Expected: FAIL — route `email.sample.send` undefined; `sampleUrl` not in the welcome view.

- [ ] **Step 4: Add the signed route in `routes/web.php`**

Add next to the existing public sample route (after line 32 `Route::post('sample', ...)->name('sample.store');`):

```php
// Signed GET from the out-of-window welcome email's "see a sample" CTA: queues
// the generic sample to the trip owner. Permanent signature (an emailed link
// must not expire), scoped to the user id it covers.
Route::get('sample/from-welcome/{user}', [SampleController::class, 'sendFromWelcome'])
    ->name('email.sample.send')
    ->middleware('signed');
```

Confirm `App\Models\User` route-model binding resolves `{user}` (it does by default). Ensure `SampleController` is already imported at the top of `routes/web.php` (it is — `sample.store` uses it).

- [ ] **Step 5: Add `sendFromWelcome` to `app/Http/Controllers/SampleController.php`**

Add these imports if missing: `use Illuminate\View\View;`. Add the method (reuses the existing private `sampleTrip()` and the injected services pattern):

```php
    /**
     * The out-of-window welcome email's "see a sample" CTA (signed GET). Queues
     * the same generic demo-destination sample to the already-known trip owner
     * and records it as a landing-sourced request for acquisition tracking, then
     * shows a calm confirmation page. No magic link — the recipient is a
     * confirmed user we resolved from the signed link.
     */
    public function sendFromWelcome(User $user, SampleForecast $sampleForecast): View
    {
        $destination = config('tripcast.sample.destination');
        $trip = $this->sampleTrip($destination, $user);
        $snapshot = $sampleForecast->forecast()->toArray();

        Mail::to($user->email)->queue(new SampleDigestMail($trip, $snapshot, route('dashboard')));

        SampleRequest::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'destination' => $destination['key'],
            'source' => SampleRequest::SOURCE_LANDING,
        ]);

        return view('sample-sent', ['email' => $user->email]);
    }
```

- [ ] **Step 6: Create `resources/views/sample-sent.blade.php`**

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your sample is on its way</title>
</head>
<body style="margin:0; padding:0; background:#F6F9FC; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center" style="padding:48px 24px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
                    <tr>
                        <td style="background:#FFFFFF; border:1px solid #E3EAF1; border-radius:14px; padding:32px;">
                            <h1 style="margin:0 0 12px; font-size:22px; color:#16202B;">Your sample is on its way</h1>
                            <p style="margin:0; font-size:16px; line-height:26px; color:#51616E;">
                                We just sent a sample tripcast to {{ $email }}. It shows exactly what your daily forecast will look like once your trip moves into the forecast window.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
```

- [ ] **Step 7: Pass `sampleUrl` into `WelcomeMail`**

In `app/Mail/WelcomeMail.php`, add the import `use Illuminate\Support\Facades\URL;` and add to the `with` array in `content()` (after line 44 `'postalAddress' => ...`):

```php
                // Signed "see a sample now" CTA (out-of-window nurture): permanent
                // signature, scoped to this confirmed user. Reuses the generic sample.
                'sampleUrl' => URL::signedRoute('email.sample.send', ['user' => $this->trip->user->id]),
```

- [ ] **Step 8: Add the CTA to `resources/views/emails/welcome.blade.php`**

Insert after the intro paragraph's closing `</p>` (line 32), before the legal-footer comment (line 34):

```blade
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:20px;">
                                <tr>
                                    <td style="border-radius:8px; background:#16202B;">
                                        <a href="{{ $sampleUrl }}" style="display:inline-block; padding:12px 20px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; font-weight:600; color:#FFFFFF; text-decoration:none;">
                                            See a sample tripcast now
                                        </a>
                                    </td>
                                </tr>
                            </table>
```

- [ ] **Step 9: Add the CTA to `resources/views/emails/welcome-text.blade.php`**

Insert after line 3 (the intro paragraph), before the legal-footer include:

```blade

Want to see one now? See a sample tripcast: {{ $sampleUrl }}
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="WelcomeSampleCtaTest|WelcomeMailTest"`
Expected: PASS.

- [ ] **Step 11: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php app/Http/Controllers/SampleController.php resources/views/sample-sent.blade.php app/Mail/WelcomeMail.php resources/views/emails/welcome.blade.php resources/views/emails/welcome-text.blade.php tests/Feature/Sample/WelcomeSampleCtaTest.php tests/Feature/Mail/WelcomeMailTest.php
git commit -m "feat(welcome): offer a sample tripcast when the trip is out of window"
```

---

### Task 5: End-to-end wiring + same-day dedup proof

Prove the two entry points behave correctly and that the immediate welcome-mode send suppresses the same-day 7am run (no double-send).

**Files:**
- Test: `tests/Feature/Trip/WelcomeFirstTripcastFlowTest.php` (create)

**Interfaces:**
- Consumes: `App\Actions\CreateTrip::handle(string $email, array $tripDetails): Trip`; `App\Console\Commands\SendDailyDigests` (artisan `digests:send`); `SendTripDigest`, `WelcomeMail`, `EmailLog`.

- [ ] **Step 1: Write the flow + dedup tests**

Create `tests/Feature/Trip/WelcomeFirstTripcastFlowTest.php`:

```php
<?php

use App\Jobs\SendTripDigest;
use App\Mail\WelcomeMail;
use App\Models\EmailLog;
use App\Models\Trip;
use App\Models\User;
use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:05:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('dispatches a welcome-mode tripcast when a confirmed user adds an in-window trip', function () {
    Bus::fake();
    $user = User::factory()->confirmed()->create();

    app(\App\Actions\CreateTrip::class)->handle($user->email, [
        'destination' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533, 'longitude' => -3.1883,
        'departure_date' => '2026-07-03', 'return_date' => '2026-07-10',
    ]);

    Bus::assertDispatched(SendTripDigest::class, fn (SendTripDigest $j) => $j->welcome === true && $j->sendDate === '2026-06-29');
});

it('queues the heads-up welcome when a confirmed user adds an out-of-window trip', function () {
    Bus::fake();
    Mail::fake();
    $user = User::factory()->confirmed()->create();

    app(\App\Actions\CreateTrip::class)->handle($user->email, [
        'destination' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533, 'longitude' => -3.1883,
        'departure_date' => '2026-08-31', 'return_date' => '2026-09-07',
    ]);

    Mail::assertQueued(WelcomeMail::class);
    Bus::assertNotDispatched(SendTripDigest::class);
});

it('does not double-send: the immediate welcome-mode send claims today so the 7am run skips the trip', function () {
    Mail::fake();
    // Real weather stub so the welcome-mode job actually claims + delivers.
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->once()->andReturn(new Forecast([
        new ForecastDay('2026-06-29', 'Sunny', 10, 20.0, 68.0, 12.0, 53.6),
        new ForecastDay('2026-07-03', 'Cloudy', 30, 18.0, 64.4, 11.0, 51.8),
    ]));
    app()->instance(WeatherProvider::class, $weather);

    $trip = User::factory()->confirmed()->create()->trips()->create([
        'destination_raw' => 'Edinburgh',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533, 'longitude' => -3.1883,
        'departure_date' => '2026-07-03', 'return_date' => '2026-07-10',
        'status' => Trip::STATUS_ACTIVE,
    ]);

    // The immediate welcome-mode send (runs synchronously here).
    (new SendTripDigest($trip, '2026-06-29', welcome: true))->handle($weather);

    // The same-day 7am run: the (trip_id, 2026-06-29) slot is already claimed.
    $this->artisan('digests:send')->assertExitCode(0);

    expect(EmailLog::where('trip_id', $trip->id)->where('send_date', '2026-06-29')->count())->toBe(1);
    Mail::assertSent(\App\Mail\DigestMail::class, 1);
});
```

- [ ] **Step 2: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=WelcomeFirstTripcastFlowTest`
Expected: PASS. (If the dedup test's `weather->shouldReceive('fetchForecast')->once()` fails because the 7am run tried a second fetch, that's a real defect — the claim must abort before fetch; do not relax the mock, fix the path.)

- [ ] **Step 3: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS across the board. Fix any welcome/digest tests that assumed the old always-queue-WelcomeMail behavior (see Task 3, Step 5).

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Trip/WelcomeFirstTripcastFlowTest.php
git commit -m "test(welcome): cover in/out-of-window flow and same-day dedup"
```

---

## Manual Testing (do this after Task 5 passes)

Automated tests prove the branching and dedup; this section is for eyeballing the
real emails and walking the flow. **Reference date: today is 2026-07-03 ET**, so
in-window = departs on/before 2026-07-10, out-of-window = departs later.

**Environment facts:**
- Emails land in the **Mailtrap sandbox inbox** (`MAIL_HOST=sandbox.smtp.mailtrap.io`) — open it in the browser to read rendered HTML + text.
- Queue is **Redis**, so queued jobs/Mailables need a worker running:
  ```bash
  php artisan queue:work
  ```
  Leave this running in a second terminal for every flow below. (Or append `--stop-when-empty` to drain once.)
- Weather uses the **live WeatherAPI key**, so an in-window tripcast shows a real forecast for the trip's coordinates.

### A. Quick template preview (no queue, no flow — checks Tasks 1 & 4 rendering)

Renders the two new email variants straight to HTML files you can open in a browser. The snapshot comes from the real weather port, so the day-row keys are always correct.

```bash
php artisan tinker --execute '
use App\Mail\DigestMail; use App\Mail\WelcomeMail; use App\Models\Trip; use App\Models\User;
use App\Services\Weather\WeatherProvider;
$user = User::factory()->confirmed()->make(["email" => "you@example.com", "temperature_unit" => User::UNIT_FAHRENHEIT]);
$trip = new Trip(["destination_raw" => "Edinburgh", "canonical_place_name" => "Edinburgh, United Kingdom", "latitude" => 55.9533, "longitude" => -3.1883, "departure_date" => now("America/New_York")->addDays(3)->toDateString(), "return_date" => now("America/New_York")->addDays(6)->toDateString(), "status" => Trip::STATUS_ACTIVE]);
$trip->setRelation("user", $user);
$snapshot = app(WeatherProvider::class)->fetchForecast(55.9533, -3.1883)->toArray();
file_put_contents(storage_path("app/preview-welcome-tripcast.html"), (new DigestMail($trip, $snapshot, now("America/New_York")->toDateString(), null, null, true))->render());
file_put_contents(storage_path("app/preview-welcome-headsup.html"), (new WelcomeMail($trip))->render());
echo "wrote storage/app/preview-welcome-tripcast.html and storage/app/preview-welcome-headsup.html\n";
'
open storage/app/preview-welcome-tripcast.html storage/app/preview-welcome-headsup.html
```

Check: the tripcast preview leads with "You're all set for Edinburgh" + "Here's your first forecast:" **above** the normal forecast rows; the heads-up preview shows the "See a sample tripcast now" button.

### B. In-window flow — confirmed user adds a trip that departs soon

1. Make sure `queue:work` is running and the Mailtrap inbox is open.
2. Create a confirmed user + in-window trip (this is the logged-in "add trip" path):
   ```bash
   php artisan tinker --execute '
   use App\Actions\CreateTrip; use App\Models\User;
   $email = "inwindow@example.com";
   User::factory()->confirmed()->create(["email" => $email]);
   app(CreateTrip::class)->handle($email, ["destination" => "Edinburgh", "canonical_place_name" => "Edinburgh, United Kingdom", "latitude" => 55.9533, "longitude" => -3.1883, "departure_date" => now("America/New_York")->addDays(4)->toDateString(), "return_date" => now("America/New_York")->addDays(8)->toDateString()]);
   echo "created in-window trip for {$email}\n";
   '
   ```
3. **Expected in Mailtrap:** one email, subject "You're all set for Edinburgh", with the welcome intro on top and a real forecast below. **No separate plain welcome.**
4. **Dedup check:** run today's scheduled send and confirm it does NOT send a second email for that trip:
   ```bash
   php artisan digests:send
   ```
   Mailtrap should still show only the one tripcast for that trip today. Confirm the DB has a single claimed row:
   ```bash
   php artisan tinker --execute 'echo App\Models\EmailLog::whereHas("trip", fn($q) => $q->where("destination_raw", "Edinburgh"))->where("send_date", now("America/New_York")->toDateString())->count();'
   ```
   Expected: `1`.

### C. Out-of-window flow — heads-up welcome + sample offer

1. Create a confirmed user + a far-out trip:
   ```bash
   php artisan tinker --execute '
   use App\Actions\CreateTrip; use App\Models\User;
   $email = "outofwindow@example.com";
   User::factory()->confirmed()->create(["email" => $email]);
   app(CreateTrip::class)->handle($email, ["destination" => "Lisbon", "canonical_place_name" => "Lisbon, Portugal", "latitude" => 38.7223, "longitude" => -9.1393, "departure_date" => now("America/New_York")->addMonths(2)->toDateString(), "return_date" => now("America/New_York")->addMonths(2)->addDays(6)->toDateString()]);
   echo "created out-of-window trip for {$email}\n";
   '
   ```
2. **Expected in Mailtrap:** the heads-up welcome — states the first-tripcast date and shows a "See a sample tripcast now" button. No forecast rows.
3. **Click the "See a sample tripcast now" button** in the Mailtrap email. It should open the "Your sample is on its way" confirmation page, and Mailtrap should receive the generic **Reykjavik** sample tripcast.
4. **Tamper check:** copy that button URL, change the `signature` query value, and open it — expect a 403 (no email queued).

### D. New-user path — magic link first, then the right welcome variant

1. Go to the site and sign up with a brand-new email, creating an in-window trip (departs within a week).
2. **Expected in Mailtrap:** only the **magic-link** email first (confirmed-first gate — no forecast before confirmation).
3. Click the magic link. On confirmation you should land on the trip's success screen, and Mailtrap should now receive the **welcome + first tripcast** (because the trip is in-window). Repeat with a far-out trip to see the heads-up + sample variant instead.

### Cleanup

```bash
rm -f storage/app/preview-welcome-tripcast.html storage/app/preview-welcome-headsup.html
```
Test users/trips created above live in your local dev DB only; remove them via tinker if you want a clean slate.

## Self-Review

- **Spec coverage:**
  - In-window → welcome carries first tripcast → Tasks 1-3, 5. ✅
  - Immediate "catch-up" send claims today's slot, 7am job skips → Task 3 (dispatch) + Task 5 (dedup proof). ✅
  - Welcome-mode reuses SendTripDigest claim/fetch/snapshot/retry → Task 2 (no logic duplicated). ✅
  - Welcome intro at top, forecast/footer/legal unchanged → Task 1. ✅
  - Out-of-window → heads-up welcome (already shows first date) + sample offer → Task 4. ✅
  - Sample reuses generic Reykjavik `SampleForecast`/`SampleDigestMail` → Task 4. ✅
  - Multiple trips at confirmation evaluated independently → each `SendWelcomeEmail::handle` call branches per trip (existing `MagicLinkController` loop, unchanged); covered implicitly by the per-trip branch tests in Task 3. ✅
  - Weather-fetch-failure and already-claimed edge cases → existing `SendTripDigest` behavior, unchanged (Task 2 adds no new failure paths); dedup interaction proven in Task 5. ✅
  - Confirmed-first gate unchanged → no edits to the confirmation trigger points other than the branch inside the shared action. ✅
- **Placeholder scan:** none — every step has concrete code, paths, and commands.
- **Type consistency:** `bool $welcome` trailing param is consistent across `DigestMail` (Task 1), `SendTripDigest` (Task 2), and the `welcome: true` dispatch (Task 3, 5). Route name `email.sample.send` consistent between `routes/web.php`, `WelcomeMail`, and tests (Task 4).

## Deferred / future scope (unchanged from spec)

- Historical-data preview of the user's *own* destination for the out-of-window case, replacing the generic Reykjavik sample.
- Final copy for the welcome-mode subject and the sample CTA wording (current values are the working defaults).
