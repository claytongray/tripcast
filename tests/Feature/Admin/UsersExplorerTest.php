<?php

use App\Models\SampleRequest;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->admin()->confirmed()->create();
});

it('lists each user with eager-loaded activity', function () {
    $alice = User::factory()->confirmed()->create(['email' => 'alice@example.com']);

    Trip::factory()->count(2)->for($alice)->create();          // active
    Trip::factory()->for($alice)->completed()->create();       // not active
    Trip::factory()->for($alice)->create(['deleted_at' => now()]); // trashed → excluded

    $alice->loginTokens()->create(['token_hash' => str_repeat('a', 64), 'expires_at' => now()->addHour(), 'consumed_at' => '2026-06-28 10:00:00']);
    $alice->loginTokens()->create(['token_hash' => str_repeat('b', 64), 'expires_at' => now()->addHour(), 'consumed_at' => '2026-06-30 10:00:00']);
    $alice->loginTokens()->create(['token_hash' => str_repeat('c', 64), 'expires_at' => now()->addHour()]); // never consumed

    $alice->sampleRequests()->create(['email' => $alice->email, 'destination' => 'Reykjavik, Iceland']);

    $this->actingAs($this->admin)
        ->get(route('admin.users', ['search' => 'alice']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Users')
            ->has('users.data', 1)
            ->where('users.data.0.email', 'alice@example.com')
            ->where('users.data.0.confirmed', true)
            ->where('users.data.0.active_trips_count', 2)
            ->where('users.data.0.last_login_at', '2026-06-30')
            ->where('users.data.0.has_sample_request', true));
});

it('shows nulls/zeros for a user with no activity', function () {
    User::factory()->create(['email' => 'nobody@example.com', 'email_verified_at' => null]);

    $this->actingAs($this->admin)
        ->get(route('admin.users', ['search' => 'nobody']))
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.confirmed', false)
            ->where('users.data.0.active_trips_count', 0)
            ->where('users.data.0.last_login_at', null)
            ->where('users.data.0.has_sample_request', false));
});

it('filters by email substring and keeps the search across pages', function () {
    User::factory()->create(['email' => 'alice@example.com']);
    User::factory()->create(['email' => 'bob@example.com']);

    $this->actingAs($this->admin)
        ->get(route('admin.users', ['search' => 'bob']))
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.email', 'bob@example.com')
            ->where('filters.search', 'bob'));
});

it('paginates at the page size', function () {
    User::factory()->count(30)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.users'))
        ->assertInertia(fn ($page) => $page
            ->where('users.per_page', 25)
            ->has('users.data', 25)
            ->where('users.last_page', 2)); // 30 seeded + admin = 31 → 2 pages
});

it('loads the list without N+1 regardless of user count', function () {
    $users = User::factory()->count(20)->create();
    $users->each(function (User $user) {
        Trip::factory()->for($user)->create();
        $user->loginTokens()->create(['token_hash' => hash('sha256', (string) $user->id), 'expires_at' => now()->addHour(), 'consumed_at' => now()]);
        SampleRequest::query()->create(['user_id' => $user->id, 'email' => $user->email, 'destination' => 'Reykjavik, Iceland']);
    });

    $this->actingAs($this->admin);

    DB::enableQueryLog();
    $this->get(route('admin.users'))->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Correlated subqueries (withCount/withMax/withExists) mean a small constant
    // number of queries — never one-per-user.
    expect($count)->toBeLessThanOrEqual(8);
});

it('guards the users explorer behind the admin Gate', function () {
    $this->get(route('admin.users'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->confirmed()->create())
        ->get(route('admin.users'))
        ->assertForbidden();
});
