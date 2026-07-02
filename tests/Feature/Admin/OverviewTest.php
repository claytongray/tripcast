<?php

use App\Models\EmailLog;
use App\Models\PromoEvent;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Pin now so the default 30-day window is [2026-06-02, 2026-07-01].
    $this->travelTo('2026-07-01 12:00:00');

    $this->admin = User::factory()->admin()->create([
        'created_at' => '2026-01-01 00:00:00',
        'email_verified_at' => '2026-01-01 00:00:00',
    ]);
});

/**
 * Seed a known fixture: 3 in-window signups (2 confirmed), trips in each status,
 * sends today + earlier, promo impressions/clicks, samples — plus out-of-window
 * rows that must be excluded.
 */
function seedOverviewFixture(): array
{
    $u1 = User::factory()->create(['created_at' => '2026-06-10 09:00:00', 'email_verified_at' => '2026-06-11 09:00:00']);
    $u2 = User::factory()->create(['created_at' => '2026-06-20 09:00:00', 'email_verified_at' => '2026-06-21 09:00:00']);
    User::factory()->create(['created_at' => '2026-07-01 08:00:00']); // unconfirmed, in window
    User::factory()->create(['created_at' => '2026-05-01 09:00:00']); // out of window

    $tripA = Trip::factory()->for($u1)->create();
    $tripB = Trip::factory()->for($u2)->create();
    $tripC = Trip::factory()->for($u1)->paused()->create();
    Trip::factory()->for($u2)->completed()->create();

    // Sends: today (2 sent, 1 failed), earlier in window, and one out of window.
    $tripA->emailLogs()->create(['send_date' => '2026-07-01', 'status' => EmailLog::STATUS_SENT]);
    $tripB->emailLogs()->create(['send_date' => '2026-07-01', 'status' => EmailLog::STATUS_SENT]);
    $tripC->emailLogs()->create(['send_date' => '2026-07-01', 'status' => EmailLog::STATUS_FAILED]);
    $tripA->emailLogs()->create(['send_date' => '2026-06-20', 'status' => EmailLog::STATUS_SENT]);
    $tripB->emailLogs()->create(['send_date' => '2026-06-20', 'status' => EmailLog::STATUS_FAILED]);
    $tripA->emailLogs()->create(['send_date' => '2026-05-01', 'status' => EmailLog::STATUS_SENT]); // out of window

    // Promo: 2 impressions / 1 click in window (CTR 50%), 1 impression out of window.
    $promo = fn (Trip $trip, string $date, string $slug, string $event) => DB::table('promo_events')->insert([
        'trip_id' => $trip->id, 'user_id' => $trip->user_id, 'send_date' => $date,
        'promo_slug' => $slug, 'event' => $event, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $promo($tripA, '2026-06-15', 'x', PromoEvent::EVENT_IMPRESSION);
    $promo($tripA, '2026-06-15', 'x', PromoEvent::EVENT_CLICK);
    $promo($tripB, '2026-06-16', 'y', PromoEvent::EVENT_IMPRESSION);
    $promo($tripA, '2026-05-01', 'z', PromoEvent::EVENT_IMPRESSION); // out of window

    // Samples: 2 in window, 1 out.
    $sample = fn (User $user, string $date) => DB::table('sample_requests')->insert([
        'user_id' => $user->id, 'email' => $user->email, 'destination' => 'Reykjavik, Iceland',
        'created_at' => $date, 'updated_at' => $date,
    ]);
    $sample($u1, '2026-06-10 09:00:00');
    $sample($u2, '2026-06-25 09:00:00');
    $sample($u1, '2026-05-01 09:00:00'); // out of window

    return compact('u1', 'u2');
}

it('renders the overview with KPIs and charts matching the data', function () {
    seedOverviewFixture();

    $this->actingAs($this->admin)
        ->get(route('admin.overview'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Overview')
            ->where('window', 30)
            ->where('dates', fn ($dates) => count($dates) === 30)
            ->where('kpis.signups.value', 3)
            ->where('kpis.signups.series', fn ($s) => count($s) === 30)
            ->where('kpis.confirmation_rate.value', 66.7)
            ->where('kpis.trips_created.value', 4)
            ->where('kpis.status_mix.active', 2)
            ->where('kpis.status_mix.paused', 1)
            ->where('kpis.status_mix.completed', 1)
            ->where('kpis.sends_today.total', 3)
            ->where('kpis.sends_today.sent', 2)
            ->where('kpis.sends_today.failed', 1)
            ->where('kpis.sends_today.success_rate', 66.7)
            ->where('kpis.promo_ctr.value', fn ($v) => (float) $v === 50.0)
            ->where('kpis.promo_ctr.clicks', 1)
            ->where('kpis.promo_ctr.impressions', 2)
            ->where('kpis.sample_requests.value', 2)
            ->where('charts.sends.0.label', 'Sent')
            ->where('charts.sends.1.label', 'Failed')
            ->where('charts.sends.0.data', fn ($d) => collect($d)->sum() === 3)
            ->where('charts.sends.1.data', fn ($d) => collect($d)->sum() === 2));
});

it('recomputes over a 7-day window', function () {
    seedOverviewFixture();

    $this->actingAs($this->admin)
        ->get(route('admin.overview', ['days' => 7]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('window', 7)
            ->where('dates', fn ($dates) => count($dates) === 7)
            ->where('kpis.signups.value', 1)); // only the 2026-07-01 signup falls in the last 7 days
});

it('falls back to the default window on an invalid days param', function (string $days) {
    $this->actingAs($this->admin)
        ->get(route('admin.overview', ['days' => $days]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('window', 30));
})->with(['45', 'abc', '0', '-7']);

it('guards the overview behind the admin Gate', function () {
    $this->get(route('admin.overview'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->confirmed()->create())
        ->get(route('admin.overview'))
        ->assertForbidden();
});

// Regression (code review): in-progress `sending` rows must not depress today's
// success rate — it's computed over terminal outcomes only, sent/(sent+failed).
it('excludes in-progress sending rows from the today success rate', function () {
    $user = User::factory()->create();
    $log = fn (string $status) => Trip::factory()->for($user)->create()
        ->emailLogs()->create(['send_date' => '2026-07-01', 'status' => $status, 'claimed_at' => now()]);

    $log(EmailLog::STATUS_SENT);
    $log(EmailLog::STATUS_SENT);
    $log(EmailLog::STATUS_FAILED);
    $log(EmailLog::STATUS_SENDING); // in-flight — excluded from the rate

    $this->actingAs($this->admin)
        ->get(route('admin.overview'))
        ->assertInertia(fn ($page) => $page
            ->where('kpis.sends_today.sent', 2)
            ->where('kpis.sends_today.failed', 1)
            ->where('kpis.sends_today.total', 4) // total still counts the sending row
            ->where('kpis.sends_today.success_rate', fn ($v) => (float) $v === 66.7)); // 2/(2+1), not 2/4
});
