<?php

use App\Models\SampleRequest;
use App\Models\User;

it('persists a sample request linked to a user', function () {
    $user = User::factory()->create();

    $row = SampleRequest::create([
        'user_id' => $user->id,
        'email' => $user->email,
        'destination' => 'reykjavik',
    ]);

    expect($row->user->is($user))->toBeTrue()
        ->and(SampleRequest::count())->toBe(1)
        ->and($row->destination)->toBe('reykjavik');
});

it('exposes the configured sample destination', function () {
    expect(config('tripcast.sample.destination.key'))->toBe('reykjavik')
        ->and(config('tripcast.sample.destination.latitude'))->toBe(64.1466);
});
