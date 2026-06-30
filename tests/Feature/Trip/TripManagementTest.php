<?php

use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('lets an owner pause an active trip', function () {
    $user = User::factory()->confirmed()->create();
    $trip = Trip::factory()->for($user)->create();

    $this->actingAs($user)
        ->patch(route('trips.pause', $trip))
        ->assertRedirect();

    expect($trip->fresh()->status)->toBe(Trip::STATUS_PAUSED);
});

it('lets an owner resume a paused trip', function () {
    $user = User::factory()->confirmed()->create();
    $trip = Trip::factory()->for($user)->paused()->create();

    $this->actingAs($user)
        ->patch(route('trips.resume', $trip))
        ->assertRedirect();

    expect($trip->fresh()->status)->toBe(Trip::STATUS_ACTIVE);
});

it('soft-deletes a trip while preserving its email_logs and feedback', function () {
    $user = User::factory()->confirmed()->create();
    $trip = Trip::factory()->for($user)->create();

    DB::table('email_logs')->insert([
        'trip_id' => $trip->id,
        'send_date' => '2026-06-29',
        'status' => 'sent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('feedback')->insert([
        'trip_id' => $trip->id,
        'send_date' => '2026-06-29',
        'reaction' => 'helped',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->delete(route('trips.destroy', $trip))
        ->assertRedirect();

    // Row is gone from default scope but present with trashed (soft delete).
    expect(Trip::find($trip->id))->toBeNull();
    expect(Trip::withTrashed()->find($trip->id))->not->toBeNull();
    $this->assertSoftDeleted('trips', ['id' => $trip->id]);

    // The audit trail survives the soft delete (AD-9).
    $this->assertDatabaseHas('email_logs', ['trip_id' => $trip->id, 'send_date' => '2026-06-29']);
    $this->assertDatabaseHas('feedback', ['trip_id' => $trip->id, 'send_date' => '2026-06-29']);
});

it('forbids acting on another users trip', function () {
    $owner = User::factory()->confirmed()->create();
    $attacker = User::factory()->confirmed()->create();
    $trip = Trip::factory()->for($owner)->create();

    $this->actingAs($attacker)->patch(route('trips.pause', $trip))->assertForbidden();
    $this->actingAs($attacker)->patch(route('trips.resume', $trip))->assertForbidden();
    $this->actingAs($attacker)->delete(route('trips.destroy', $trip))->assertForbidden();

    expect($trip->fresh()->status)->toBe(Trip::STATUS_ACTIVE);
    expect($trip->fresh())->not->toBeNull();
});

it('redirects guests to login on management routes', function () {
    $user = User::factory()->confirmed()->create();
    $trip = Trip::factory()->for($user)->create();

    $this->patch(route('trips.pause', $trip))->assertRedirect(route('login'));
    $this->delete(route('trips.destroy', $trip))->assertRedirect(route('login'));
});

it('does not error when pausing a completed (terminal) trip', function () {
    $user = User::factory()->confirmed()->create();
    $trip = Trip::factory()->for($user)->completed()->create();

    $this->actingAs($user)
        ->patch(route('trips.pause', $trip))
        ->assertRedirect();

    // Terminal state is preserved; no 500 from the domain guard.
    expect($trip->fresh()->status)->toBe(Trip::STATUS_COMPLETED);
});
