<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-07-01 12:00:00'); // default 30-day window: [2026-06-02, 2026-07-01]
    $this->admin = User::factory()->admin()->confirmed()->create();
});

function sampleReq(User $user, string $destination, string $createdAt): void
{
    DB::table('sample_requests')->insert([
        'user_id' => $user->id,
        'email' => $user->email,
        'destination' => $destination,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

function seedSampleFixture(): void
{
    $u1 = User::factory()->confirmed()->create();
    $u2 = User::factory()->confirmed()->create();
    $u3 = User::factory()->create(['email_verified_at' => null]); // unconfirmed

    // Reykjavik tops the list (3); u1 requests twice (distinct requester counts once).
    sampleReq($u1, 'Reykjavik, Iceland', '2026-06-10 09:00:00');
    sampleReq($u1, 'Reykjavik, Iceland', '2026-06-15 09:00:00');
    sampleReq($u2, 'Reykjavik, Iceland', '2026-06-20 09:00:00');
    sampleReq($u2, 'Tokyo, Japan', '2026-06-21 09:00:00');
    sampleReq($u3, 'Paris, France', '2026-06-22 09:00:00');

    // Out of window — excluded.
    sampleReq($u1, 'Reykjavik, Iceland', '2026-05-01 09:00:00');
}

it('shows the sample funnel: over time, top destinations, and conversion', function () {
    seedSampleFixture();

    $this->actingAs($this->admin)
        ->get(route('admin.samples'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Samples')
            ->where('totals.requests', 5)       // rows in window
            ->where('totals.requesters', 3)      // distinct users
            ->where('totals.confirmed_requesters', 2) // u1, u2 confirmed
            ->where('totals.conversion_rate', 66.7)   // 2/3
            ->where('top_destinations.0.destination', 'Reykjavik, Iceland')
            ->where('top_destinations.0.count', 3)
            ->where('top_destinations.1.destination', 'Paris, France') // ties broken alphabetically
            ->where('top_destinations.2.destination', 'Tokyo, Japan')
            ->where('requests.0.data', fn ($d) => collect($d)->sum() === 5 && count($d) === 30));
});

it('recomputes over a 7-day window and falls back on invalid input', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.samples', ['days' => 7]))
        ->assertInertia(fn ($page) => $page->where('window', 7)->where('dates', fn ($d) => count($d) === 7));

    $this->actingAs($this->admin)
        ->get(route('admin.samples', ['days' => 'x']))
        ->assertInertia(fn ($page) => $page->where('window', 30));
});

it('guards the samples section behind the admin Gate', function () {
    $this->get(route('admin.samples'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->confirmed()->create())
        ->get(route('admin.samples'))
        ->assertForbidden();
});
