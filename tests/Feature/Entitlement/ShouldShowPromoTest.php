<?php

use App\Models\User;

it('shows a promo to a free-tier user', function () {
    expect(User::factory()->create()->shouldShowPromo())->toBeTrue();
});

it('hides the promo from an ad-free user', function () {
    expect(User::factory()->adFree()->create()->shouldShowPromo())->toBeFalse();
});

it('keeps plan out of mass assignment (data-settable only, no checkout)', function () {
    // A request-style create cannot flip the entitlement; it defaults to free.
    $user = User::create([
        'email' => 'mass@example.com',
        'plan' => User::PLAN_AD_FREE,
    ]);

    expect($user->fresh()->plan)->toBe(User::PLAN_FREE)
        ->and($user->fresh()->shouldShowPromo())->toBeTrue();
});
