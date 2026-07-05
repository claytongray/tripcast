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
