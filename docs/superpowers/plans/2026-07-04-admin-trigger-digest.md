# Admin-triggered trip digest — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin send any trip's daily digest on demand — to themselves (preview, the default) or, rarely, to the trip owner (a real forced resend) — from the Admin → Monitoring page.

**Architecture:** Extract the forecast+narration+promo assembly out of `SendTripDigest` into a shared `DigestComposer` (approach A). A new synchronous `AdminDigestSender` drives an admin-triggered send through that composer, honoring owner suppression, recording the outcome in a new out-of-band `admin_email_sends` audit table (never `email_logs`, never a `PromoEvent`). A new mutating admin controller + a split-button on Monitoring.vue expose it.

**Tech Stack:** Laravel 13, PHP 8.3, Pest 4, Inertia v3 + Vue 3, Tailwind v4, Wayfinder.

## Global Constraints

- **Dedup index is sacred (AD-3):** never write an admin-triggered send to `email_logs`; that table's `unique(trip_id, send_date)` is the scheduled-send claim authority. Admin sends live only in `admin_email_sends`.
- **Never distort metrics:** admin sends fire **no** `PromoEvent` impression and appear in **no** `EmailHealthMetrics` query.
- **Honor suppression (AD-11/AD-13):** send-to-owner refuses if the owner is unconfirmed (`email_verified_at` null) or opted out (`email_opted_out` true). Send-to-me is always allowed.
- **Compose never throws (AD-17):** narration/promo failures degrade to a null line / null slot, never a thrown/failed send.
- **Send clock is `now('America/New_York')` (AD-7):** the admin send-date anchor, same as `digests:run`.
- **Style:** run `vendor/bin/pint --dirty --format agent` before every commit that touches PHP. Constructor property promotion, explicit return types, curly braces always. Follow `PromoItemController` conventions for the mutating admin surface.
- **Tests:** Pest. Run with `php artisan test --compact --filter=<name>`. Pin the clock in beforeEach where dates matter: `Carbon::setTestNow(Carbon::parse('2026-06-29 09:05:00', 'America/New_York'))`.

---

### Task 1: Extract `DigestComposer` and refactor `SendTripDigest` to use it

Behavior-preserving extraction. Move `narrate()`, `narrateSafely()`, and `selectPromo()` out of `SendTripDigest` into a shared `DigestComposer` that returns a `ComposedDigest` value object (the `DigestMail` + the selected `Promo`). `SendTripDigest` keeps its claim/log/impression flow; it just delegates assembly.

**Files:**
- Create: `app/Services/Digest/ComposedDigest.php`
- Create: `app/Services/Digest/DigestComposer.php`
- Modify: `app/Jobs/SendTripDigest.php`
- Create: `tests/Feature/Digest/DigestComposerTest.php`
- Existing regression: `tests/Feature/Digest/SendTripDigestTest.php` (must stay green, unchanged)

**Interfaces:**
- Produces:
  - `App\Services\Digest\ComposedDigest` — `public function __construct(public readonly DigestMail $mail, public readonly ?Promo $promo)`
  - `App\Services\Digest\DigestComposer::compose(Trip $trip, array $snapshot, string $sendDate, bool $welcome = false): ComposedDigest`

- [ ] **Step 1: Write the failing composer test**

Create `tests/Feature/Digest/DigestComposerTest.php`:

```php
<?php

use App\Models\Trip;
use App\Models\User;
use App\Services\Digest\ComposedDigest;
use App\Services\Digest\DigestComposer;
use App\Services\Promo\Promo;
use App\Services\Promo\PromoProvider;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:05:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function composerSnapshot(): array
{
    return ['days' => [['date' => '2026-06-29', 'conditionText' => 'Sunny', 'precipChance' => 10, 'highC' => 20.0, 'highF' => 68.0, 'lowC' => 12.0, 'lowF' => 53.6]], 'limited' => true];
}

it('wires the snapshot into a DigestMail and selects a promo for free-plan users', function () {
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();
    $promo = new Promo('rain-jacket', 'Rain jacket', null, 'https://example.test/j');

    $this->mock(PromoProvider::class)
        ->shouldReceive('select')->once()->andReturn($promo);

    $composed = app(DigestComposer::class)->compose($trip, composerSnapshot(), '2026-06-29');

    expect($composed)->toBeInstanceOf(ComposedDigest::class)
        ->and($composed->mail->snapshot)->toBe(composerSnapshot())
        ->and($composed->promo)->toBe($promo);
});

it('selects no promo for ad-free users (entitlement gate, AD-19)', function () {
    $trip = Trip::factory()->for(User::factory()->confirmed()->adFree()->create())->create();

    $this->mock(PromoProvider::class)->shouldNotReceive('select');

    $composed = app(DigestComposer::class)->compose($trip, composerSnapshot(), '2026-06-29');

    expect($composed->promo)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DigestComposerTest`
Expected: FAIL — `Class "App\Services\Digest\DigestComposer" not found`.

- [ ] **Step 3: Create the `ComposedDigest` value object**

Create `app/Services/Digest/ComposedDigest.php`:

```php
<?php

namespace App\Services\Digest;

use App\Mail\DigestMail;
use App\Services\Promo\Promo;

/**
 * The output of {@see DigestComposer::compose()}: a ready-to-send DigestMail
 * plus the promo that was selected for it. The caller decides what to do with
 * the promo — the scheduled job records an impression on the sent path; the
 * admin sender deliberately never does (out-of-band, no analytics distortion).
 */
final class ComposedDigest
{
    public function __construct(
        public readonly DigestMail $mail,
        public readonly ?Promo $promo,
    ) {}
}
```

- [ ] **Step 4: Create the `DigestComposer` service (logic moved verbatim from the job)**

Create `app/Services/Digest/DigestComposer.php`:

```php
<?php

namespace App\Services\Digest;

use App\Mail\DigestMail;
use App\Models\EmailLog;
use App\Models\Trip;
use App\Models\User;
use App\Services\Narration\ClaudeNarrator;
use App\Services\Narration\NarrationContext;
use App\Services\Narration\Narrator;
use App\Services\Promo\Promo;
use App\Services\Promo\PromoProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single authority for assembling a digest (AD-17/AD-18/AD-19): the
 * day-over-day narration line and the entitlement-gated promo slot, folded into
 * a ready-to-send DigestMail. Shared by the scheduled SendTripDigest job and the
 * admin-triggered AdminDigestSender so the narration/promo seam has one home and
 * never drifts. All internals are guarded — any failure yields a null line / null
 * slot, never a thrown or delayed send.
 */
class DigestComposer
{
    public function __construct(
        private readonly Narrator $narrator,
        private readonly PromoProvider $promoProvider,
    ) {}

    /**
     * @param  array{days: list<array<string, mixed>>, limited: bool}  $snapshot
     */
    public function compose(Trip $trip, array $snapshot, string $sendDate, bool $welcome = false): ComposedDigest
    {
        $narration = $this->narrate($trip, $snapshot, $sendDate);
        $promo = $this->selectPromo($trip, $snapshot, $sendDate);

        return new ComposedDigest(
            new DigestMail($trip, $snapshot, $sendDate, $narration, $promo, $welcome),
            $promo,
        );
    }

    /**
     * Select the one weather-keyed promo (AD-18), gated on entitlement (AD-19)
     * and guarded: only free-tier users see a promo, and any selection failure
     * yields no slot.
     *
     * @param  array{days: list<array<string, mixed>>, limited: bool}  $snapshot
     */
    private function selectPromo(Trip $trip, array $snapshot, string $sendDate): ?Promo
    {
        if (! $trip->user->shouldShowPromo()) {
            return null;
        }

        try {
            return $this->promoProvider->select($snapshot, $sendDate);
        } catch (Throwable $e) {
            Log::warning('promo selection failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Build the calm day-over-day line (AD-17). Reads the prior send's snapshot
     * for this trip (AD-9, read-only), runs the live deterministic narrator, and
     * — when shadow is enabled — logs the Claude line alongside for comparison.
     * Strictly off the delivery path: any error yields a null line.
     *
     * @param  array{days: list<array<string, mixed>>, limited: bool}  $snapshot
     */
    private function narrate(Trip $trip, array $snapshot, string $sendDate): ?string
    {
        $prior = EmailLog::query()
            ->where('trip_id', $trip->id)
            ->where('send_date', '<', $sendDate)
            ->whereNotNull('weather_snapshot')
            ->orderByDesc('send_date')
            ->first()?->weather_snapshot;

        $context = new NarrationContext(
            priorSnapshot: $prior,
            currentSnapshot: $snapshot,
            celsius: $trip->user->temperature_unit === User::UNIT_CELSIUS,
            departureDate: $trip->departure_date->toDateString(),
            returnDate: $trip->return_date->toDateString(),
        );

        $line = $this->narrateSafely($this->narrator, $context);

        if (config('tripcast.narration.shadow')) {
            $shadow = $this->narrateSafely(app(ClaudeNarrator::class), $context);

            Log::info('narrator:compare', [
                'trip_id' => $trip->id,
                'send_date' => $sendDate,
                'deterministic' => $line,
                'claude' => $shadow,
            ]);
        }

        return $line;
    }

    /**
     * Run a narrator, swallowing any failure (AD-17: never break/delay the send).
     */
    private function narrateSafely(Narrator $narrator, NarrationContext $context): ?string
    {
        try {
            return $narrator->narrate($context);
        } catch (Throwable $e) {
            Log::warning('narration failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
```

- [ ] **Step 5: Run the composer test to verify it passes**

Run: `php artisan test --compact --filter=DigestComposerTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Refactor `SendTripDigest` to delegate to the composer**

In `app/Jobs/SendTripDigest.php`:

1. Replace the `handle()` body from the narration/promo section onward. Delete the `$narration = $this->narrate(...)` and `$promo = $this->selectPromo(...)` lines and the `$this->deliver($log, $snapshot, $narration, $promo);` call, replacing them with:

```php
        // Assemble narration + promo + the mail once, via the shared composer
        // (AD-17/AD-18). Never inside the delivery retry, never re-fetching weather.
        $composed = app(DigestComposer::class)->compose($this->trip, $snapshot, $this->sendDate, $this->welcome);

        $this->deliver($log, $composed);
```

2. Delete the now-moved private methods `selectPromo()`, `narrate()`, and `narrateSafely()` entirely.

3. Change `deliver()`'s signature and body to consume the `ComposedDigest`:

```php
    /**
     * Render + deliver the digest from the composed mail with bounded, in-process
     * retry (AD-4). The job stays tries = 1 (the queue must never re-dispatch);
     * retry is delivery-only — weather is never re-fetched. Always terminal:
     * `sent`, or `failed` + reason (recovered by the next day's run).
     */
    private function deliver(EmailLog $log, ComposedDigest $composed): void
    {
        $maxAttempts = (int) config('tripcast.send.max_delivery_attempts');
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                Mail::to($this->trip->user->email)->send($composed->mail);

                $log->update(['status' => EmailLog::STATUS_SENT]);

                // Promo impression (FR-18, AD-18): logged once on the sent path,
                // idempotent per (trip_id, send_date, slug, impression). Guarded —
                // an attribution write must never fail the (already-sent) digest.
                $this->recordImpression($composed->promo);

                return;
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        // Never a broken digest (AD-4): bounded retries exhausted → terminal
        // failure, recovered by the next day's run (a new send_date).
        $log->update([
            'status' => EmailLog::STATUS_FAILED,
            'failure_reason' => 'delivery: '.$lastError?->getMessage(),
        ]);
    }
```

4. Fix the imports: add `use App\Services\Digest\ComposedDigest;` and `use App\Services\Digest\DigestComposer;`. Remove imports now unused by the job: `NarrationContext`, `Narrator`, `ClaudeNarrator`, `PromoProvider` (keep `Promo` only if still referenced — after this change `recordImpression(?Promo $promo)` still references it, so keep `use App\Services\Promo\Promo;`). Keep `Log`, `Throwable`, `Mail`, `EmailLog`, `PromoEvent`, `Trip`, `User`, `WeatherProvider`, `WeatherProviderFailedException`, `UniqueConstraintViolationException`.

- [ ] **Step 7: Run the full digest suite to prove the extract is behavior-preserving**

Run: `php artisan test --compact --filter=Digest`
Expected: PASS — every `SendTripDigestTest`, `SendDailyDigestsTest`, and `DigestComposerTest` case green, zero changes to `SendTripDigestTest`.

- [ ] **Step 8: Lint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Digest app/Jobs/SendTripDigest.php tests/Feature/Digest/DigestComposerTest.php
git commit -m "refactor(digest): extract DigestComposer shared by job + admin send"
```

---

### Task 2: `admin_email_sends` table + `AdminEmailSend` model

The out-of-band audit trail. Separate table so it never touches the sacred `email_logs` dedup index and is invisible to send-health metrics.

**Files:**
- Create: `database/migrations/2026_07_04_000001_create_admin_email_sends_table.php` (use `php artisan make:migration`)
- Create: `app/Models/AdminEmailSend.php`
- Modify: `app/Models/Trip.php` (add `adminEmailSends` relation)
- Create: `database/factories/AdminEmailSendFactory.php`
- Create: `tests/Feature/Admin/AdminEmailSendModelTest.php`

**Interfaces:**
- Produces:
  - `App\Models\AdminEmailSend` with string constants `RECIPIENT_OWNER='owner'`, `RECIPIENT_ADMIN='admin'`, `STATUS_SENT='sent'`, `STATUS_FAILED='failed'`; fillable `trip_id, admin_user_id, recipient, recipient_email, status, failure_reason`; relations `trip()`, `admin()`.
  - `Trip::adminEmailSends(): HasMany<AdminEmailSend>`

- [ ] **Step 1: Write the failing model test**

Create `tests/Feature/Admin/AdminEmailSendModelTest.php`:

```php
<?php

use App\Models\AdminEmailSend;
use App\Models\Trip;
use App\Models\User;

it('persists an audit row and resolves its trip, admin, and constants', function () {
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();

    $send = AdminEmailSend::create([
        'trip_id' => $trip->id,
        'admin_user_id' => $admin->id,
        'recipient' => AdminEmailSend::RECIPIENT_OWNER,
        'recipient_email' => $trip->user->email,
        'status' => AdminEmailSend::STATUS_SENT,
        'failure_reason' => null,
    ]);

    expect($send->trip->is($trip))->toBeTrue()
        ->and($send->admin->is($admin))->toBeTrue()
        ->and($trip->adminEmailSends()->count())->toBe(1)
        ->and(AdminEmailSend::RECIPIENT_ADMIN)->toBe('admin')
        ->and(AdminEmailSend::STATUS_FAILED)->toBe('failed');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AdminEmailSendModelTest`
Expected: FAIL — `Class "App\Models\AdminEmailSend" not found`.

- [ ] **Step 3: Create the migration**

Run: `php artisan make:migration create_admin_email_sends_table --no-interaction`

Then set its `up()`/`down()`:

```php
    public function up(): void
    {
        Schema::create('admin_email_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('recipient');       // owner | admin
            $table->string('recipient_email');
            $table->string('status');          // sent | failed
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'created_at']); // per-trip trail, newest first
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_email_sends');
    }
```

- [ ] **Step 4: Create the model**

Create `app/Models/AdminEmailSend.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audit trail for admin-triggered digest sends — deliberately separate from
 * `email_logs` (AD-3): it never collides with the sacred (trip_id, send_date)
 * dedup index and is invisible to send-health metrics (AD-9). One row per admin
 * trigger, capturing who sent what to whom and the outcome.
 *
 * @property int $id
 * @property int $trip_id
 * @property int $admin_user_id
 * @property string $recipient
 * @property string $recipient_email
 * @property string $status
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdminEmailSend extends Model
{
    /** @use HasFactory<\Database\Factories\AdminEmailSendFactory> */
    use HasFactory;

    public const RECIPIENT_OWNER = 'owner';

    public const RECIPIENT_ADMIN = 'admin';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'trip_id',
        'admin_user_id',
        'recipient',
        'recipient_email',
        'status',
        'failure_reason',
    ];

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
```

- [ ] **Step 5: Add the `adminEmailSends` relation to `Trip`**

In `app/Models/Trip.php`, alongside the existing relations, add (and `use Illuminate\Database\Eloquent\Relations\HasMany;` if not already imported):

```php
    /**
     * Admin-triggered send audit rows for this trip (out-of-band, not email_logs).
     *
     * @return HasMany<AdminEmailSend, $this>
     */
    public function adminEmailSends(): HasMany
    {
        return $this->hasMany(AdminEmailSend::class);
    }
```

- [ ] **Step 6: Create the factory**

Create `database/factories/AdminEmailSendFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\AdminEmailSend;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminEmailSend>
 */
class AdminEmailSendFactory extends Factory
{
    protected $model = AdminEmailSend::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trip_id' => Trip::factory(),
            'admin_user_id' => User::factory()->admin(),
            'recipient' => AdminEmailSend::RECIPIENT_ADMIN,
            'recipient_email' => fake()->safeEmail(),
            'status' => AdminEmailSend::STATUS_SENT,
            'failure_reason' => null,
        ];
    }
}
```

- [ ] **Step 7: Run the model test to verify it passes**

Run: `php artisan test --compact --filter=AdminEmailSendModelTest`
Expected: PASS.

- [ ] **Step 8: Lint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/AdminEmailSend.php app/Models/Trip.php database/factories/AdminEmailSendFactory.php tests/Feature/Admin/AdminEmailSendModelTest.php
git commit -m "feat(admin): add admin_email_sends audit table + model"
```

---

### Task 3: `AdminDigestSender` service + `SuppressedRecipientException`

Drives one admin-triggered send synchronously: suppression check (owner only) → fresh forecast → compose → deliver → audit row. Never writes `email_logs`, never records a `PromoEvent`.

**Files:**
- Create: `app/Services/Digest/SuppressedRecipientException.php`
- Create: `app/Services/Digest/AdminDigestSender.php`
- Create: `tests/Feature/Digest/AdminDigestSenderTest.php`

**Interfaces:**
- Consumes: `DigestComposer::compose(...)` (Task 1), `AdminEmailSend` (Task 2), `WeatherProvider::fetchForecast(float $lat, float $lng, ?string $tz): Forecast`.
- Produces:
  - `App\Services\Digest\SuppressedRecipientException extends \RuntimeException`
  - `AdminDigestSender::sendToOwner(Trip $trip, User $admin): AdminEmailSend` (throws `SuppressedRecipientException`)
  - `AdminDigestSender::sendToAdmin(Trip $trip, User $admin): AdminEmailSend`

- [ ] **Step 1: Write the failing sender tests**

Create `tests/Feature/Digest/AdminDigestSenderTest.php`:

```php
<?php

use App\Mail\DigestMail;
use App\Models\AdminEmailSend;
use App\Models\EmailLog;
use App\Models\PromoEvent;
use App\Models\Trip;
use App\Models\User;
use App\Services\Digest\AdminDigestSender;
use App\Services\Digest\SuppressedRecipientException;
use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:05:00', 'America/New_York'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function adminForecast(): Forecast
{
    return new Forecast([
        new ForecastDay('2026-06-29', 'Sunny', 10, 20.0, 68.0, 12.0, 53.6),
    ]);
}

function bindWeather(): void
{
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->andReturn(adminForecast());
    app()->instance(WeatherProvider::class, $weather);
}

it('sends to the admin, audits it, and touches neither email_logs nor promo_events', function () {
    Mail::fake();
    bindWeather();
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();

    $send = app(AdminDigestSender::class)->sendToAdmin($trip, $admin);

    expect($send->status)->toBe(AdminEmailSend::STATUS_SENT)
        ->and($send->recipient)->toBe(AdminEmailSend::RECIPIENT_ADMIN)
        ->and($send->recipient_email)->toBe($admin->email)
        ->and(EmailLog::count())->toBe(0)
        ->and(PromoEvent::count())->toBe(0);

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->hasTo($admin->email));
});

it('force-sends to the owner even when today already sent, without a second email_logs row', function () {
    Mail::fake();
    bindWeather();
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();
    EmailLog::create(['trip_id' => $trip->id, 'send_date' => '2026-06-29', 'status' => EmailLog::STATUS_SENT, 'claimed_at' => now()]);

    $send = app(AdminDigestSender::class)->sendToOwner($trip, $admin);

    expect($send->status)->toBe(AdminEmailSend::STATUS_SENT)
        ->and($send->recipient_email)->toBe($trip->user->email)
        ->and(EmailLog::count())->toBe(1); // unchanged — no out-of-band row in email_logs

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->hasTo($trip->user->email));
});

it('refuses send-to-owner for an opted-out owner', function () {
    Mail::fake();
    bindWeather();
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->optedOut()->create())->create();

    expect(fn () => app(AdminDigestSender::class)->sendToOwner($trip, $admin))
        ->toThrow(SuppressedRecipientException::class);

    expect(AdminEmailSend::count())->toBe(0);
    Mail::assertNothingSent();
});

it('refuses send-to-owner for an unconfirmed owner', function () {
    Mail::fake();
    bindWeather();
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->create())->create(); // no ->confirmed()

    expect(fn () => app(AdminDigestSender::class)->sendToOwner($trip, $admin))
        ->toThrow(SuppressedRecipientException::class);

    expect(AdminEmailSend::count())->toBe(0);
});

it('records a failed audit row when the weather fetch fails', function () {
    Mail::fake();
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->andThrow(new WeatherProviderFailedException('provider down'));
    app()->instance(WeatherProvider::class, $weather);
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();

    $send = app(AdminDigestSender::class)->sendToAdmin($trip, $admin);

    expect($send->status)->toBe(AdminEmailSend::STATUS_FAILED)
        ->and($send->failure_reason)->toContain('weather:');
    Mail::assertNothingSent();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AdminDigestSenderTest`
Expected: FAIL — `Class "App\Services\Digest\AdminDigestSender" not found`.

- [ ] **Step 3: Create the exception**

Create `app/Services/Digest/SuppressedRecipientException.php`:

```php
<?php

namespace App\Services\Digest;

use RuntimeException;

/**
 * Thrown when an admin send-to-owner is refused because the owner is suppressed
 * (unconfirmed, AD-11; or opted out, AD-13). The message is a human-readable
 * reason surfaced to the admin — the admin action is not a backdoor around
 * account-level email suppression.
 */
class SuppressedRecipientException extends RuntimeException {}
```

- [ ] **Step 4: Create the sender service**

Create `app/Services/Digest/AdminDigestSender.php`:

```php
<?php

namespace App\Services\Digest;

use App\Models\AdminEmailSend;
use App\Models\Trip;
use App\Models\User;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Drives one admin-triggered digest send end to end, synchronously so the admin
 * gets an immediate pass/fail. Uses the shared DigestComposer for assembly and
 * records the outcome only in `admin_email_sends` — never `email_logs` (AD-3),
 * never a PromoEvent (out-of-band; no metric distortion). Send-to-owner honors
 * owner suppression (AD-11/AD-13); send-to-me is a preview and is always allowed.
 */
class AdminDigestSender
{
    public function __construct(
        private readonly WeatherProvider $weather,
        private readonly DigestComposer $composer,
    ) {}

    /**
     * Force-send a real digest to the trip owner (bypassing the same-day dedup
     * lock), refusing if the owner is suppressed.
     *
     * @throws SuppressedRecipientException
     */
    public function sendToOwner(Trip $trip, User $admin): AdminEmailSend
    {
        $this->assertDeliverable($trip->user);

        return $this->send($trip, $admin, AdminEmailSend::RECIPIENT_OWNER, $trip->user->email);
    }

    /**
     * Preview-send the digest to the acting admin's own address. Always allowed.
     */
    public function sendToAdmin(Trip $trip, User $admin): AdminEmailSend
    {
        return $this->send($trip, $admin, AdminEmailSend::RECIPIENT_ADMIN, $admin->email);
    }

    /**
     * @throws SuppressedRecipientException
     */
    private function assertDeliverable(User $owner): void
    {
        if (! $owner->hasConfirmedEmail()) {
            throw new SuppressedRecipientException('the owner has not confirmed their email');
        }

        if ($owner->email_opted_out) {
            throw new SuppressedRecipientException('the owner has opted out of all email');
        }
    }

    private function send(Trip $trip, User $admin, string $recipient, string $email): AdminEmailSend
    {
        $sendDate = now('America/New_York')->toDateString();

        try {
            $forecast = $this->weather->fetchForecast(
                $trip->latitude,
                $trip->longitude,
                $trip->destination_timezone,
            );
        } catch (WeatherProviderFailedException $e) {
            return $this->record($trip, $admin, $recipient, $email, AdminEmailSend::STATUS_FAILED, 'weather: '.$e->getMessage());
        }

        // Compose but ignore the promo: admin sends record no impression (AD-18).
        $composed = $this->composer->compose($trip, $forecast->toArray(), $sendDate);

        try {
            Mail::to($email)->send($composed->mail);
        } catch (Throwable $e) {
            return $this->record($trip, $admin, $recipient, $email, AdminEmailSend::STATUS_FAILED, 'delivery: '.$e->getMessage());
        }

        return $this->record($trip, $admin, $recipient, $email, AdminEmailSend::STATUS_SENT, null);
    }

    private function record(Trip $trip, User $admin, string $recipient, string $email, string $status, ?string $reason): AdminEmailSend
    {
        return AdminEmailSend::create([
            'trip_id' => $trip->id,
            'admin_user_id' => $admin->id,
            'recipient' => $recipient,
            'recipient_email' => $email,
            'status' => $status,
            'failure_reason' => $reason,
        ]);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AdminDigestSenderTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Lint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Digest/SuppressedRecipientException.php app/Services/Digest/AdminDigestSender.php tests/Feature/Digest/AdminDigestSenderTest.php
git commit -m "feat(admin): AdminDigestSender for on-demand trip digests"
```

---

### Task 4: Admin controller + route + form request

Expose the sender over HTTP, inside the existing `auth` + `can:admin` group, flashing the outcome.

**Files:**
- Create: `app/Http/Requests/SendTripDigestRequest.php`
- Create: `app/Http/Controllers/Admin/TripDigestController.php`
- Modify: `routes/web.php` (add the POST route inside the admin group; add controller import)
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (share `flash.error`)
- Create: `tests/Feature/Admin/AdminTripDigestControllerTest.php`

**Interfaces:**
- Consumes: `AdminDigestSender` (Task 3), `AdminEmailSend` (Task 2).
- Produces: named route `admin.trips.digest.send` → `POST admin/trips/{trip}/digest`.

- [ ] **Step 1: Write the failing controller test**

Create `tests/Feature/Admin/AdminTripDigestControllerTest.php`:

```php
<?php

use App\Mail\DigestMail;
use App\Models\AdminEmailSend;
use App\Models\Trip;
use App\Models\User;
use App\Services\Weather\Forecast;
use App\Services\Weather\ForecastDay;
use App\Services\Weather\WeatherProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:05:00', 'America/New_York'));
    $weather = Mockery::mock(WeatherProvider::class);
    $weather->shouldReceive('fetchForecast')->andReturn(new Forecast([
        new ForecastDay('2026-06-29', 'Sunny', 10, 20.0, 68.0, 12.0, 53.6),
    ]));
    app()->instance(WeatherProvider::class, $weather);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('rejects guests and non-admins', function () {
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();

    $this->post(route('admin.trips.digest.send', $trip), ['recipient' => 'admin'])
        ->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->post(route('admin.trips.digest.send', $trip), ['recipient' => 'admin'])
        ->assertForbidden();
});

it('sends a preview to the admin and flashes success', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();

    $this->actingAs($admin)
        ->post(route('admin.trips.digest.send', $trip), ['recipient' => 'admin'])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(AdminEmailSend::where('recipient', 'admin')->where('status', 'sent')->count())->toBe(1);
    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->hasTo($admin->email));
});

it('force-sends to the owner and flashes success', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();

    $this->actingAs($admin)
        ->post(route('admin.trips.digest.send', $trip), ['recipient' => 'owner'])
        ->assertRedirect()
        ->assertSessionHas('status');

    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => $m->hasTo($trip->user->email));
});

it('refuses send-to-owner for a suppressed owner and flashes an error', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->optedOut()->create())->create();

    $this->actingAs($admin)
        ->post(route('admin.trips.digest.send', $trip), ['recipient' => 'owner'])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(AdminEmailSend::count())->toBe(0);
    Mail::assertNothingSent();
});

it('validates the recipient', function () {
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();

    $this->actingAs($admin)
        ->post(route('admin.trips.digest.send', $trip), ['recipient' => 'nobody'])
        ->assertSessionHasErrors('recipient');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AdminTripDigestControllerTest`
Expected: FAIL — route `admin.trips.digest.send` not defined.

- [ ] **Step 3: Create the form request**

Create `app/Http/Requests/SendTripDigestRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\AdminEmailSend;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an admin's on-demand digest trigger. Defense-in-depth: `authorize()`
 * re-checks the admin Gate on top of the route group's `can:admin` (AD-12).
 */
class SendTripDigestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipient' => ['required', Rule::in([AdminEmailSend::RECIPIENT_OWNER, AdminEmailSend::RECIPIENT_ADMIN])],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/Admin/TripDigestController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendTripDigestRequest;
use App\Models\AdminEmailSend;
use App\Models\Trip;
use App\Services\Digest\AdminDigestSender;
use App\Services\Digest\SuppressedRecipientException;
use Illuminate\Http\RedirectResponse;

/**
 * On-demand digest trigger (mutating admin surface). Registered inside the single
 * `['auth','can:admin']->prefix('admin')` group (AD-12). Synchronous so the admin
 * gets an immediate pass/fail flash; the send itself is fully out-of-band
 * (`admin_email_sends` only — never `email_logs`, never a PromoEvent).
 */
class TripDigestController extends Controller
{
    public function send(SendTripDigestRequest $request, Trip $trip, AdminDigestSender $sender): RedirectResponse
    {
        $recipient = $request->validated('recipient');
        $admin = $request->user();

        try {
            $send = $recipient === AdminEmailSend::RECIPIENT_OWNER
                ? $sender->sendToOwner($trip, $admin)
                : $sender->sendToAdmin($trip, $admin);
        } catch (SuppressedRecipientException $e) {
            return back()->with('error', "Can't send to owner: {$e->getMessage()}.");
        }

        if ($send->status === AdminEmailSend::STATUS_FAILED) {
            return back()->with('error', "Send failed: {$send->failure_reason}.");
        }

        $message = $recipient === AdminEmailSend::RECIPIENT_OWNER
            ? "Sent to owner ({$send->recipient_email})."
            : "Sent to you ({$send->recipient_email}).";

        return back()->with('status', $message);
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/web.php`, add the import near the other admin controller imports:

```php
use App\Http\Controllers\Admin\TripDigestController;
```

Inside the `Route::middleware(['auth', 'can:admin'])->prefix('admin')->group(...)` block, after the `monitoring` route, add:

```php
    // On-demand digest trigger from Monitoring (mutating). Inherits the group's
    // single admin Gate (AD-12). Out-of-band: never writes email_logs.
    Route::post('trips/{trip}/digest', [TripDigestController::class, 'send'])->name('admin.trips.digest.send');
```

- [ ] **Step 6: Share `flash.error` to Inertia**

In `app/Http/Middleware/HandleInertiaRequests.php`, extend the `flash` array:

```php
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'error' => fn () => $request->session()->get('error'),
            ],
```

- [ ] **Step 7: Run the controller test to verify it passes**

Run: `php artisan test --compact --filter=AdminTripDigestControllerTest`
Expected: PASS (5 tests).

- [ ] **Step 8: Regenerate Wayfinder types + lint + commit**

```bash
php artisan wayfinder:generate
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/SendTripDigestRequest.php app/Http/Controllers/Admin/TripDigestController.php routes/web.php app/Http/Middleware/HandleInertiaRequests.php resources/js/actions resources/js/routes tests/Feature/Admin/AdminTripDigestControllerTest.php
git commit -m "feat(admin): route + controller to trigger a trip digest"
```

---

### Task 5: Surface owner state + admin-send trail on the Monitoring payload

Give the frontend what it needs: whether the owner can receive an owner-send, and the recent admin-send trail per trip.

**Files:**
- Modify: `app/Http/Controllers/AdminController.php` (`monitoring()`)
- Create: `tests/Feature/Admin/AdminMonitoringPayloadTest.php`

**Interfaces:**
- Consumes: `Trip::adminEmailSends` (Task 2), `User::hasConfirmedEmail()`, `User::$email_opted_out`.
- Produces: per-trip payload keys `owner_opted_out: bool`, `owner_confirmed: bool`, `adminSends: array<{recipient,status,created_at}>`.

- [ ] **Step 1: Write the failing payload test**

Create `tests/Feature/Admin/AdminMonitoringPayloadTest.php`:

```php
<?php

use App\Models\AdminEmailSend;
use App\Models\Trip;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('exposes owner suppression state and the admin-send trail per trip', function () {
    $admin = User::factory()->admin()->create();
    $trip = Trip::factory()->for(User::factory()->confirmed()->create())->create();
    AdminEmailSend::factory()->create([
        'trip_id' => $trip->id,
        'admin_user_id' => $admin->id,
        'recipient' => AdminEmailSend::RECIPIENT_ADMIN,
        'status' => AdminEmailSend::STATUS_SENT,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.monitoring'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Monitoring')
            ->where('trips.0.owner_confirmed', true)
            ->where('trips.0.owner_opted_out', false)
            ->has('trips.0.adminSends', 1)
            ->where('trips.0.adminSends.0.recipient', 'admin')
            ->where('trips.0.adminSends.0.status', 'sent')
        );
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AdminMonitoringPayloadTest`
Expected: FAIL — missing `owner_confirmed` / `adminSends` keys.

- [ ] **Step 3: Extend `monitoring()`**

In `app/Http/Controllers/AdminController.php`, add `use App\Models\AdminEmailSend;` to the imports. Update the eager-load and the per-trip map:

Change the query's `->with([...])` to also load the trail newest-first:

```php
        $trips = Trip::query()
            ->with([
                'user',
                'emailLogs' => fn ($query) => $query->orderByDesc('send_date'),
                'adminEmailSends' => fn ($query) => $query->orderByDesc('created_at')->limit(10),
            ])
            ->orderByDesc('id')
            ->get();
```

Add these three keys inside the `$trips->map(fn (Trip $trip) => [ ... ])` array, after `'status' => $trip->status,`:

```php
                'owner_confirmed' => $trip->user->hasConfirmedEmail(),
                'owner_opted_out' => (bool) $trip->user->email_opted_out,
                'adminSends' => $trip->adminEmailSends->map(fn (AdminEmailSend $send): array => [
                    'recipient' => $send->recipient,
                    'status' => $send->status,
                    'created_at' => $send->created_at?->toDateTimeString(),
                ])->all(),
```

- [ ] **Step 4: Run the payload test to verify it passes**

Run: `php artisan test --compact --filter=AdminMonitoringPayloadTest`
Expected: PASS.

- [ ] **Step 5: Lint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AdminController.php tests/Feature/Admin/AdminMonitoringPayloadTest.php
git commit -m "feat(admin): expose owner state + admin-send trail on monitoring"
```

---

### Task 6: Split-button UI on `Admin/Monitoring.vue`

**Send to me** is the primary click; a caret dropdown holds the rare **Send to owner**. Flash messages render at the top; the trail shows under each trip.

**Files:**
- Modify: `resources/js/pages/Admin/Monitoring.vue`

**Interfaces:**
- Consumes: Wayfinder action for `admin.trips.digest.send` (from Task 4's `wayfinder:generate`), payload keys from Task 5, `flash.status`/`flash.error` (Task 4).

- [ ] **Step 1: Confirm the Wayfinder route helper exists**

Run: `grep -rl "trips/{trip}/digest\|admin.trips.digest" resources/js/routes resources/js/actions`
Expected: a file (e.g. `resources/js/routes/admin/index.ts` or an `actions` file) referencing the new route. Import the split-button pieces and this helper into the component. If nothing is found, re-run `php artisan wayfinder:generate` (Task 4 Step 8) before proceeding.

- [ ] **Step 2: Update the component script + template**

In `resources/js/pages/Admin/Monitoring.vue`:

1. Extend the `<script setup>` imports and props. Add to the existing imports:

```ts
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { send as sendTripDigest } from '@/routes/admin/trips/digest';
```

> Note: use the exact import path `grep` surfaced in Step 1 for the route helper; the line above is the expected Wayfinder location. It must resolve to a function returning `{ url, method }` for `POST admin/trips/{trip}/digest`.

2. Extend the `AdminTrip` interface with the new payload fields:

```ts
interface AdminSendRow {
    recipient: 'owner' | 'admin';
    status: SendStatus;
    created_at: string | null;
}

interface AdminTrip {
    id: number;
    owner: string;
    destination_raw: string;
    canonical_place_name: string;
    departure_date: string;
    return_date: string;
    status: TripStatus;
    owner_confirmed: boolean;
    owner_opted_out: boolean;
    latestSnapshot: { send_date: string; status: SendStatus } | null;
    emailLogs: EmailLogRow[];
    adminSends: AdminSendRow[];
}
```

3. Add flash + send state below `defineProps`:

```ts
const page = usePage();
const flashStatus = computed(() => page.props.flash?.status as string | null);
const flashError = computed(() => page.props.flash?.error as string | null);
const sending = ref<number | null>(null);

function post(trip: AdminTrip, recipient: 'owner' | 'admin') {
    sending.value = trip.id;
    router.post(
        sendTripDigest(trip.id).url,
        { recipient },
        {
            preserveScroll: true,
            onFinish: () => {
                sending.value = null;
            },
        },
    );
}

function sendToMe(trip: AdminTrip) {
    post(trip, 'admin');
}

function sendToOwner(trip: AdminTrip) {
    if (window.confirm(`Send a real digest to ${trip.owner}?`)) {
        post(trip, 'owner');
    }
}

function ownerReason(trip: AdminTrip): string | null {
    if (!trip.owner_confirmed) return 'Owner has not confirmed their email';
    if (trip.owner_opted_out) return 'Owner has opted out of all email';
    return null;
}
```

Add `ref` to the `vue` import if not present: `import { computed, ref } from 'vue';`.

4. Add the flash banners just inside `<main>`, above the empty-state paragraph:

```vue
        <p
            v-if="flashStatus"
            class="rounded-md border border-transparent bg-surface-wash p-3 text-body text-positive"
        >
            {{ flashStatus }}
        </p>
        <p
            v-if="flashError"
            class="rounded-md border border-transparent bg-surface-wash p-3 text-body text-destructive"
        >
            {{ flashError }}
        </p>
```

5. Inside each trip `<section>`, add the split button as its own row after the header `<div class="flex flex-wrap ...">` block (before the `<table>`):

```vue
            <div class="flex items-center gap-2">
                <div class="flex items-stretch">
                    <Button
                        variant="outline"
                        class="rounded-r-none"
                        :disabled="sending === trip.id"
                        @click="sendToMe(trip)"
                    >
                        Send to me
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="outline"
                                class="rounded-l-none border-l-0 px-2"
                                :disabled="sending === trip.id"
                                aria-label="More send options"
                            >
                                ▾
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                :disabled="ownerReason(trip) !== null"
                                @select="sendToOwner(trip)"
                            >
                                Send to owner
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
                <span v-if="ownerReason(trip)" class="text-meta text-ink-secondary">
                    {{ ownerReason(trip) }}
                </span>
            </div>
```

6. Add the admin-send trail after the email-logs `<table>`/empty paragraph, inside the `<section>`:

```vue
            <p
                v-if="trip.adminSends.length > 0"
                class="text-meta text-ink-secondary"
            >
                Admin sends:
                <span
                    v-for="(s, i) in trip.adminSends"
                    :key="i"
                    :class="sendPill[s.status]"
                >
                    {{ s.recipient }} ({{ s.status }}){{ i < trip.adminSends.length - 1 ? ', ' : '' }}
                </span>
            </p>
```

- [ ] **Step 3: Build the frontend**

Run: `npm run build`
Expected: builds with no type errors (the new imports + interface resolve).

- [ ] **Step 4: Smoke test the page renders (no JS errors)**

Run: `php artisan test --compact --filter=AdminMonitoringPayloadTest`
Expected: PASS (server payload unchanged; confirms the route still renders `Admin/Monitoring`).

- [ ] **Step 5: Manual verification**

With `npm run dev` (or `composer run dev`) running, logged in as `claytonjgray@gmail.com`:
1. Visit `/admin/monitoring`. Each trip shows a **Send to me** split button.
2. Click **Send to me** → success banner "Sent to you (…)"; the digest lands in the local log mailer / Mailtrap; an "Admin sends: admin (sent)" line appears under the trip.
3. On the Edinburgh trip (already sent 2026-06-30), open the caret → **Send to owner** → confirm → success banner; then check `/admin/emails` totals did **not** change.
4. Opt a user out (`php artisan tinker --execute '\App\Models\User::where("email","…")->first()->optOut();'`) → that trip's **Send to owner** item is disabled with the reason shown.

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/Admin/Monitoring.vue
git commit -m "feat(admin): split-button to trigger a trip digest from monitoring"
```

---

## Self-Review

**Spec coverage:**
- Split-button (me default, owner in dropdown) → Task 6. ✓
- Send-to-owner forces past dedup, out-of-band, no impression → Task 3 (tests assert `EmailLog::count()` unchanged, `PromoEvent::count()===0`). ✓
- Honor suppression on owner-send → Task 3 (opted-out + unconfirmed tests) + Task 4 (error flash) + Task 6 (disabled item). ✓
- Send-to-me faithful promo minus impression → Task 3 composes (promo selected) but never records; Task 1 gates promo by plan. ✓
- Shared `DigestComposer` (approach A) → Task 1, with full `SendTripDigest` suite as regression. ✓
- `admin_email_sends` audit table, off metrics, off `email_logs` index → Task 2. ✓
- Monitoring trail + owner state payload → Task 5. ✓
- Synchronous, immediate pass/fail → Task 3/4. ✓

**Placeholder scan:** No TBD/TODO; every code step shows complete code. The one lookup step (Task 6 Step 1) is a real `grep` with a fallback, not a placeholder. ✓

**Type consistency:** `ComposedDigest{mail,promo}`, `DigestComposer::compose(Trip,array,string,bool)`, `AdminDigestSender::sendToOwner/sendToAdmin(Trip,User): AdminEmailSend`, `SuppressedRecipientException`, constants `RECIPIENT_OWNER/RECIPIENT_ADMIN/STATUS_SENT/STATUS_FAILED`, route `admin.trips.digest.send`, payload keys `owner_confirmed/owner_opted_out/adminSends` — all consistent across tasks. ✓
