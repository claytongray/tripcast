<?php

use function Pest\Laravel\get;

// FR-26 — public legal pages render for a logged-out visitor, no auth anywhere.
it('renders the privacy page for a guest', function () {
    get('/privacy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Privacy'));
});

it('renders the terms page for a guest', function () {
    get('/terms')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Terms'));
});

it('names the routes privacy and terms for email footer links', function () {
    expect(route('privacy'))->toBe(url('/privacy'))
        ->and(route('terms'))->toBe(url('/terms'));
});
