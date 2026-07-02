<?php

use App\Mail\MagicLinkMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withSession;

beforeEach(function () {
    Mail::fake();
    RateLimiter::clear('magic-link:maya@example.com');
    RateLimiter::clear('magic-link-ip:127.0.0.1');
});

/**
 * The URLs of every queued magic-link email so far, in send order.
 *
 * @return list<string>
 */
function queuedMagicLinkUrls(): array
{
    $urls = [];

    Mail::assertQueued(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$urls) {
        $urls[] = $mail->url;

        return true;
    });

    return $urls;
}

// Resend within the window reuses the still-valid link (same raw token, same
// emailed URL) rather than rotating — so a delayed first email still works.
it('reuses the same link on resend within the window', function () {
    post('/login', ['email' => 'maya@example.com'])->assertRedirect(route('login.sent'));

    $user = User::where('email', 'maya@example.com')->first();
    $firstHash = $user->loginTokens()->whereNull('consumed_at')->value('token_hash');

    post('/login', ['email' => 'maya@example.com'])->assertRedirect(route('login.sent'));

    $tokens = $user->loginTokens()->get();
    expect($tokens)->toHaveCount(1)
        ->and($tokens->first()->token_hash)->toBe($firstHash);

    $urls = queuedMagicLinkUrls();
    expect($urls)->toHaveCount(2)
        ->and($urls[0])->toBe($urls[1]);
});

// Mirrors the real browser sequence: request, land on the "check your inbox"
// page (an intermediate GET), then hit Resend. The interstitial visit must not
// drop the stashed link.
it('reuses the link after landing on the sent page in between', function () {
    post('/login', ['email' => 'maya@example.com']);
    $user = User::where('email', 'maya@example.com')->first();
    $firstHash = $user->loginTokens()->value('token_hash');

    get('/login/sent')->assertOk();

    post('/login', ['email' => 'maya@example.com']);

    expect($user->loginTokens()->sole()->token_hash)->toBe($firstHash);
});

// Reuse never extends the window: the row keeps its original expiry, and the
// resent email advertises the REMAINING minutes, not a fresh full TTL.
it('keeps the original expiry on reuse and emails the remaining minutes', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    post('/login', ['email' => 'maya@example.com']);
    $user = User::where('email', 'maya@example.com')->first();
    $originalExpiry = $user->loginTokens()->value('expires_at');

    Carbon::setTestNow(Carbon::parse('2026-07-01 12:10:00'));
    post('/login', ['email' => 'maya@example.com']);

    expect($user->loginTokens()->sole()->expires_at->equalTo($originalExpiry))->toBeTrue();

    $ttls = [];
    Mail::assertQueued(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$ttls) {
        $ttls[] = $mail->ttlMinutes;

        return true;
    });
    expect($ttls[0])->toBe(15)->and($ttls[1])->toBe(5);

    Carbon::setTestNow();
});

// If the stashed link was already consumed (e.g. clicked in another tab), a
// resend must mint a fresh one rather than re-mailing a dead link.
it('issues a fresh link on resend when the prior was consumed', function () {
    post('/login', ['email' => 'maya@example.com']);
    $user = User::where('email', 'maya@example.com')->first();
    $firstHash = $user->loginTokens()->value('token_hash');

    $user->loginTokens()->update(['consumed_at' => now()]);

    post('/login', ['email' => 'maya@example.com']);

    $live = $user->loginTokens()->whereNull('consumed_at')->get();
    expect($live)->toHaveCount(1)
        ->and($live->first()->token_hash)->not->toBe($firstHash);

    $urls = queuedMagicLinkUrls();
    expect($urls[0])->not->toBe($urls[1]);
});

// A stashed link that has aged past its expiry is not reusable — regenerate.
it('issues a fresh link on resend when the prior expired', function () {
    post('/login', ['email' => 'maya@example.com']);
    $user = User::where('email', 'maya@example.com')->first();
    $firstHash = $user->loginTokens()->value('token_hash');

    $user->loginTokens()->update(['expires_at' => now()->subMinute()]);

    post('/login', ['email' => 'maya@example.com']);

    $live = $user->loginTokens()->whereNull('consumed_at')->where('expires_at', '>', now())->get();
    expect($live)->toHaveCount(1)
        ->and($live->first()->token_hash)->not->toBe($firstHash);
});

// Reuse is scoped to the same browser (the link lives in the session). A
// different browser has nothing stashed, so it rotates as before.
it('does not reuse across a different browser session', function () {
    post('/login', ['email' => 'maya@example.com']);
    $user = User::where('email', 'maya@example.com')->first();
    $firstHash = $user->loginTokens()->value('token_hash');

    $this->flushSession();

    post('/login', ['email' => 'maya@example.com']);

    expect($user->loginTokens()->whereNull('consumed_at')->sole()->token_hash)->not->toBe($firstHash);

    $urls = queuedMagicLinkUrls();
    expect($urls[0])->not->toBe($urls[1]);
});

// A stashed link belongs to one email; requesting a link for a DIFFERENT email
// in the same browser must not re-mail the first account's link — it issues a
// fresh one, leaving the first account's token untouched.
it('does not reuse a stashed link for a different email in the same browser', function () {
    RateLimiter::clear('magic-link:aya@example.com');
    RateLimiter::clear('magic-link:ben@example.com');

    post('/login', ['email' => 'aya@example.com']);
    post('/login', ['email' => 'ben@example.com']);

    $urls = queuedMagicLinkUrls();
    expect($urls)->toHaveCount(2)
        ->and($urls[0])->not->toBe($urls[1]);

    $aya = User::where('email', 'aya@example.com')->first();
    $ben = User::where('email', 'ben@example.com')->first();
    expect($ben->loginTokens()->whereNull('consumed_at')->count())->toBe(1)
        ->and($aya->loginTokens()->whereNull('consumed_at')->count())->toBe(1);
});

// The interstitial ("expires in N min") must reflect the REMAINING minutes on a
// reuse, not a fresh full TTL — assert the flashed value the page renders.
it('flashes the remaining minutes to the interstitial on reuse', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));
    post('/login', ['email' => 'maya@example.com']);

    Carbon::setTestNow(Carbon::parse('2026-07-01 12:10:00'));
    post('/login', ['email' => 'maya@example.com'])
        ->assertSessionHas('magic_ttl', 5)
        ->assertSessionHas('magic_intent', 'login');

    Carbon::setTestNow();
});

// A brand-new signup's delayed activation link must survive a resend (the
// highest-value case), and the interstitial must keep its signup ("start your
// tripcast") copy rather than flipping to "sign in".
it('reuses the signup activation link and keeps signup intent on resend', function () {
    RateLimiter::clear('magic-link:newbie@example.com');

    withSession(['pending_trip' => [
        'destination' => 'Edinburgh',
        'departure_date' => '2026-08-10',
        'return_date' => '2026-08-17',
        'canonical_place_name' => 'Edinburgh, United Kingdom',
        'latitude' => 55.9533,
        'longitude' => -3.1883,
    ]])
        ->post('/trip', ['email' => 'newbie@example.com'])
        ->assertRedirect(route('login.sent'))
        ->assertSessionHas('magic_intent', 'signup');

    $user = User::where('email', 'newbie@example.com')->first();
    $firstHash = $user->loginTokens()->whereNull('consumed_at')->value('token_hash');

    // The shared interstitial "Resend" button POSTs to /login.
    post('/login', ['email' => 'newbie@example.com'])
        ->assertRedirect(route('login.sent'))
        ->assertSessionHas('magic_intent', 'signup');

    expect($user->loginTokens()->sole()->token_hash)->toBe($firstHash);
});
