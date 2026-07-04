<?php

use App\Actions\RequestMagicLink;
use App\Models\User;
use App\Services\Geocoding\FakeGeocoder;
use App\Services\Geocoding\Geocoder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\withSession;

beforeEach(function () {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'America/New_York'));
    app()->bind(Geocoder::class, FakeGeocoder::class);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Issue a usable magic link for a token-holder and return the raw token.
 */
function issueAnalyticsToken(User $user): string
{
    $raw = Str::random(64);

    $user->loginTokens()->create([
        'token_hash' => RequestMagicLink::hash($raw),
        'expires_at' => now()->addMinutes(15),
    ]);

    return $raw;
}

// --- trip_created --------------------------------------------------------

it('flashes trip_created from the landing email-capture flow', function () {
    withSession(['pending_trip' => [
        'destination' => 'Edinburgh',
        'departure_date' => '2026-07-10',
        'return_date' => '2026-07-17',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533,
        'longitude' => -3.1883,
    ]])
        ->post('/trip', ['email' => 'maya@example.com'])
        ->assertRedirect(route('login.sent'))
        ->assertInertiaFlash('analytics.event', 'trip_created')
        ->assertInertiaFlash('analytics.params.source', 'landing');
});

it('flashes trip_created from the dashboard add-trip flow', function () {
    $user = User::factory()->confirmed()->create();

    actingAs($user)
        ->post(route('trips.store'), [
            'destination' => 'Edinburgh',
            'departure_date' => '2026-07-14',
            'return_date' => '2026-07-21',
        ])
        ->assertRedirect()
        ->assertInertiaFlash('analytics.event', 'trip_created')
        ->assertInertiaFlash('analytics.params.source', 'dashboard');
});

// --- login_link_requested ------------------------------------------------

it('flashes login_link_requested when a magic link is requested', function () {
    post('/login', ['email' => 'maya@example.com'])
        ->assertRedirect(route('login.sent'))
        ->assertInertiaFlash('analytics.event', 'login_link_requested');
});

// --- sign_up / login -----------------------------------------------------

it('flashes sign_up on the first (email-confirming) consume', function () {
    $user = User::factory()->create();
    $raw = issueAnalyticsToken($user);

    post(route('magic.consume.store', ['token' => $raw]))
        ->assertRedirect()
        ->assertInertiaFlash('analytics.event', 'sign_up')
        ->assertInertiaFlash('analytics.params.method', 'magic_link');
});

it('flashes login on a returning (already-confirmed) user consume', function () {
    $user = User::factory()->confirmed()->create();
    $raw = issueAnalyticsToken($user);

    post(route('magic.consume.store', ['token' => $raw]))
        ->assertRedirect()
        ->assertInertiaFlash('analytics.event', 'login')
        ->assertInertiaFlash('analytics.params.method', 'magic_link');
});

// --- sample_requested ----------------------------------------------------

it('flashes sample_requested from the public sample form', function () {
    post(route('sample.store'), ['email' => 'sampler@example.com'])
        ->assertRedirect()
        ->assertInertiaFlash('analytics.event', 'sample_requested')
        ->assertInertiaFlash('analytics.params.source', 'landing');
});

it('flashes sample_requested from the dashboard sample action', function () {
    $user = User::factory()->confirmed()->create();
    RateLimiter::clear('sample-self:'.$user->id);

    actingAs($user)
        ->post(route('sample.self'))
        ->assertRedirect()
        ->assertInertiaFlash('analytics.event', 'sample_requested')
        ->assertInertiaFlash('analytics.params.source', 'dashboard');
});

// --- feedback_submitted --------------------------------------------------

it('flashes feedback_submitted from the feedback form', function () {
    $user = User::factory()->create();
    RateLimiter::clear('feedback:'.$user->id);

    actingAs($user)
        ->post(route('feedback.store'), [
            'message' => 'Love the calm morning digests.',
            'source' => 'dashboard',
        ])
        ->assertRedirect()
        ->assertInertiaFlash('analytics.event', 'feedback_submitted')
        ->assertInertiaFlash('analytics.params.source', 'dashboard');
});

// --- base gtag.js tag (config-gated) -------------------------------------

it('omits the GA snippet when no measurement id is configured', function () {
    config(['services.google_analytics.measurement_id' => null]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('googletagmanager.com/gtag/js', false);
});

it('renders the GA snippet when a measurement id is configured', function () {
    config(['services.google_analytics.measurement_id' => 'G-TEST123']);

    $this->get('/')
        ->assertOk()
        ->assertSee('googletagmanager.com/gtag/js?id=G-TEST123', false);
});
