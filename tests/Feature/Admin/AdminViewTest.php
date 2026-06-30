<?php

use App\Models\EmailLog;
use App\Models\Trip;
use App\Models\User;

it('redirects guests to login', function () {
    $this->get(route('admin'))->assertRedirect(route('login'));
});

it('forbids authenticated non-admins', function () {
    $this->actingAs(User::factory()->confirmed()->create())
        ->get(route('admin'))
        ->assertForbidden();
});

it('shows every trip and send to an admin', function () {
    $admin = User::factory()->admin()->confirmed()->create();

    $alice = User::factory()->confirmed()->create(['email' => 'alice@example.com']);
    $bob = User::factory()->confirmed()->create(['email' => 'bob@example.com']);

    $aliceTrip = Trip::factory()->for($alice)->create(['canonical_place_name' => 'Paris, France']);
    Trip::factory()->for($bob)->create(['canonical_place_name' => 'Tokyo, Japan']);

    $aliceTrip->emailLogs()->create([
        'send_date' => '2026-06-28',
        'status' => EmailLog::STATUS_SENT,
    ]);
    $aliceTrip->emailLogs()->create([
        'send_date' => '2026-06-29',
        'status' => EmailLog::STATUS_FAILED,
        'failure_reason' => 'SMTP 550 rejected',
    ]);

    $this->actingAs($admin)
        ->get(route('admin'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin')
            ->has('trips', 2) // alice + bob; the admin has no trips of their own
            ->where('trips', fn ($trips) => collect($trips)->pluck('owner')->contains('alice@example.com')
                && collect($trips)->pluck('owner')->contains('bob@example.com')));
});

it('surfaces the email log and latest-snapshot reference for a trip', function () {
    $admin = User::factory()->admin()->confirmed()->create();
    $user = User::factory()->confirmed()->create(['email' => 'cara@example.com']);
    $trip = Trip::factory()->for($user)->create();

    $trip->emailLogs()->create(['send_date' => '2026-06-28', 'status' => EmailLog::STATUS_SENT]);
    $trip->emailLogs()->create([
        'send_date' => '2026-06-29',
        'status' => EmailLog::STATUS_FAILED,
        'failure_reason' => 'SMTP 550 rejected',
    ]);

    $this->actingAs($admin)
        ->get(route('admin'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('trips.0.owner', 'cara@example.com')
            ->where('trips.0.latestSnapshot.send_date', '2026-06-29')
            ->where('trips.0.latestSnapshot.status', EmailLog::STATUS_FAILED)
            ->has('trips.0.emailLogs', 2)
            ->where('trips.0.emailLogs.0.failure_reason', 'SMTP 550 rejected'));
});
