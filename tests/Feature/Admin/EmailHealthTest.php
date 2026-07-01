<?php

use App\Console\Commands\SendDailyDigests;
use App\Models\EmailLog;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->travelTo('2026-07-01 12:00:00'); // default 30-day window: [2026-06-02, 2026-07-01]
    Cache::forget(SendDailyDigests::LAST_RUN_CACHE_KEY);
    $this->admin = User::factory()->admin()->confirmed()->create();
});

function seedEmailHealthFixture(): Trip
{
    $trip = Trip::factory()->for(User::factory())->create();

    // In-window sends: 2 sent, 3 failed (1 weather, 1 delivery, 1 other).
    $trip->emailLogs()->create(['send_date' => '2026-06-20', 'status' => EmailLog::STATUS_SENT]);
    $trip->emailLogs()->create(['send_date' => '2026-06-21', 'status' => EmailLog::STATUS_SENT]);
    $trip->emailLogs()->create(['send_date' => '2026-06-22', 'status' => EmailLog::STATUS_FAILED, 'failure_reason' => 'weather: no forecast']);
    $trip->emailLogs()->create(['send_date' => '2026-06-23', 'status' => EmailLog::STATUS_FAILED, 'failure_reason' => 'delivery: SMTP 550']);
    $trip->emailLogs()->create(['send_date' => '2026-06-24', 'status' => EmailLog::STATUS_FAILED, 'failure_reason' => 'unexpected']);

    // Out of window — excluded.
    $trip->emailLogs()->create(['send_date' => '2026-05-01', 'status' => EmailLog::STATUS_SENT]);

    // Stuck-sending: one stale (past the 30-min lease), one fresh.
    $trip->emailLogs()->create(['send_date' => '2026-06-30', 'status' => EmailLog::STATUS_SENDING, 'claimed_at' => now()->subMinutes(31)]);
    $trip->emailLogs()->create(['send_date' => '2026-06-29', 'status' => EmailLog::STATUS_SENDING, 'claimed_at' => now()->subMinutes(5)]);

    return $trip;
}

it('reports send health from email_logs', function () {
    seedEmailHealthFixture();

    $this->actingAs($this->admin)
        ->get(route('admin.emails'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Emails')
            ->where('totals.sent', 2)
            ->where('totals.failed', 3)
            ->where('totals.total', 5)
            ->where('totals.success_rate', fn ($v) => (float) $v === 40.0)
            ->where('failures_by_reason.weather', 1)
            ->where('failures_by_reason.delivery', 1)
            ->where('failures_by_reason.other', 1)
            ->where('stuck_sending', 1) // only the stale sending row
            ->where('sends.0.label', 'Sent')
            ->where('sends.1.label', 'Failed')
            ->where('sends.0.data', fn ($d) => collect($d)->sum() === 2)
            ->where('sends.1.data', fn ($d) => collect($d)->sum() === 3));
});

it('surfaces the cached daily-run liveness snapshot', function () {
    Cache::put(SendDailyDigests::LAST_RUN_CACHE_KEY, [
        'healthy' => true, 'due' => 4, 'dispatched' => 4, 'duration_ms' => 120,
        'error' => null, 'ran_at' => '2026-07-01T09:00:00+00:00',
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.emails'))
        ->assertInertia(fn ($page) => $page
            ->where('liveness.healthy', true)
            ->where('liveness.due', 4)
            ->where('liveness.dispatched', 4));
});

it('shows null liveness when no run has been recorded', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.emails'))
        ->assertInertia(fn ($page) => $page->where('liveness', null));
});

it('recomputes over a 7-day window and falls back on invalid input', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.emails', ['days' => 7]))
        ->assertInertia(fn ($page) => $page->where('window', 7)->where('dates', fn ($d) => count($d) === 7));

    $this->actingAs($this->admin)
        ->get(route('admin.emails', ['days' => 'abc']))
        ->assertInertia(fn ($page) => $page->where('window', 30));
});

it('guards the emails section behind the admin Gate', function () {
    $this->get(route('admin.emails'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->confirmed()->create())
        ->get(route('admin.emails'))
        ->assertForbidden();
});
