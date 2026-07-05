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
