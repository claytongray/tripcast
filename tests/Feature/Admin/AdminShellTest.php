<?php

use App\Models\User;

/**
 * The admin panel is one Gate-guarded route group (Epic 7, AD-12): every section
 * redirects guests to login and 403s authenticated non-admins, and renders its
 * own Inertia page for an admin. The bare /admin prefix redirects to overview.
 */
dataset('admin_sections', [
    'overview' => ['admin.overview', 'Admin/Overview'],
    'users' => ['admin.users', 'Admin/Users'],
    'emails' => ['admin.emails', 'Admin/Emails'],
    'promos' => ['admin.promos', 'Admin/Promos'],
    'samples' => ['admin.samples', 'Admin/Samples'],
    'monitoring' => ['admin.monitoring', 'Admin/Monitoring'],
]);

it('redirects guests to login', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with('admin_sections');

it('forbids authenticated non-admins', function (string $routeName) {
    $this->actingAs(User::factory()->confirmed()->create())
        ->get(route($routeName))
        ->assertForbidden();
})->with('admin_sections');

it('renders each section for an admin', function (string $routeName, string $component) {
    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->get(route($routeName))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component($component));
})->with('admin_sections');

it('redirects the bare /admin prefix to overview for an admin', function () {
    $this->actingAs(User::factory()->admin()->confirmed()->create())
        ->get('/admin')
        ->assertRedirect(route('admin.overview'));
});

it('redirects the bare /admin prefix to login for a guest', function () {
    $this->get('/admin')->assertRedirect(route('login'));
});
