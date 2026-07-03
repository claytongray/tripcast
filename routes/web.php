<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailAction;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PlaceSuggestController;
use App\Http\Controllers\PromoItemController;
use App\Http\Controllers\PromoRedirect;
use App\Http\Controllers\SampleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TripController;
use Illuminate\Support\Facades\Route;

// Public landing hero + inline trip-setup form (FR-1). No auth: the form
// submits before any account exists, for guests and logged-in users alike.
// The POST endpoints are throttled per IP — they hit Google geocoding, create
// records, and trigger emails, so they must not be scriptable unbounded.
Route::get('/', [LandingController::class, 'show'])->name('home');
Route::post('/', [LandingController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('trip-setup.store');

// Trip-detail passive confirm (Story 1.3) + email capture → atomic create (Story 1.4).
Route::get('trip', [LandingController::class, 'tripDetail'])->name('trip.detail');
Route::post('trip', [LandingController::class, 'createTrip'])
    ->middleware('throttle:20,1')
    ->name('trip.store');

// Public sample tripcast (MVP): emails a sample whose "Get started" CTA is a
// magic link. Throttled in-controller, sharing the magic-link buckets.
Route::post('sample', [SampleController::class, 'store'])->name('sample.store');

// Signed GET from the out-of-window welcome email's "see a sample" CTA: queues
// the generic sample to the trip owner. Permanent signature (an emailed link
// must not expire), scoped to the user id it covers.
Route::get('sample/from-welcome/{user}', [SampleController::class, 'sendFromWelcome'])
    ->name('email.sample.send')
    ->middleware('signed');

// Destination autocomplete proxy (FR-22, AD-1): keeps the restricted Google
// key server-side. Generous per-IP budget — the client debounces keystrokes
// across the two destination fields; failures return an empty list, not errors.
Route::get('places/suggest', PlaceSuggestController::class)
    ->middleware('throttle:120,1')
    ->name('places.suggest');

// Legal & compliance pages (FR-26): public, static, linked from email footers
// (and the site footer, Story 9.2). Named routes feed route('privacy'/'terms')
// absolute URLs in the emails.
Route::inertia('privacy', 'Privacy')->name('privacy');
Route::inertia('terms', 'Terms')->name('terms');

// Authenticated trip dashboard (FR-12). View + manage status; all status writes
// route through Trip::transitionTo() (AD-5), owner-scoped by TripPolicy.
Route::middleware('auth')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Add-trip (Story 3.2). Throttled like the landing POST — it hits geocoding,
    // creates records, and queues mail, so it must not be scriptable unbounded.
    Route::post('trips', [TripController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('trips.store');
    Route::get('trips/{trip}/added', [TripController::class, 'added'])->name('trips.added');

    Route::patch('trips/{trip}/pause', [TripController::class, 'pause'])->name('trips.pause');
    Route::patch('trips/{trip}/resume', [TripController::class, 'resume'])->name('trips.resume');
    Route::delete('trips/{trip}', [TripController::class, 'destroy'])->name('trips.destroy');

    // Account settings (Spec A): the temperature unit is the one editable
    // preference; email is read-only.
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');

    // Dashboard "send a sample" card: emails the fixed demo-destination sample
    // to the signed-in user. No magic link and no SampleRequest row — those are
    // acquisition mechanics for the public /sample endpoint.
    Route::post('sample/self', [SampleController::class, 'storeForSelf'])->name('sample.self');

    // Site feedback (Story 10.1): free-text note queued to the team inbox,
    // reply-to the sender. Email-only — distinct from the digest-reaction
    // `feedback` table. Limited in-controller like sample/self.
    Route::post('feedback', [FeedbackController::class, 'store'])->name('feedback.store');
});

// Admin observability panel (Epic 7, FR-22). One guarded, prefixed group — the
// single Gate (AD-12) guards every section: authed + admin, guests → login,
// authed non-admins → 403. Read-only throughout. Monitoring (FR-13) is folded in
// as one section, renamed from the former standalone `admin` route.
Route::middleware(['auth', 'can:admin'])->prefix('admin')->group(function () {
    // Bare /admin → overview (keeps old bookmarks working; the "Admin" entry link
    // targets overview). Inherits the group's auth + can:admin.
    Route::redirect('/', '/admin/overview');

    Route::get('overview', [AdminController::class, 'overview'])->name('admin.overview');
    Route::get('users', [AdminController::class, 'users'])->name('admin.users');
    Route::get('emails', [AdminController::class, 'emails'])->name('admin.emails');
    Route::get('promos', [AdminController::class, 'promos'])->name('admin.promos');
    Route::get('samples', [AdminController::class, 'samples'])->name('admin.samples');
    Route::get('monitoring', [AdminController::class, 'monitoring'])->name('admin.monitoring');

    // Sponsored-catalog management (Epic 8, FR-26) — the first *mutating* admin
    // surface. Registered inside this group so all six verbs (incl. writes)
    // inherit the single admin Gate (AD-12); no second policy. A string to
    // names() prefixes every route → admin.promo-items.{index,create,…}.
    Route::resource('promo-items', PromoItemController::class)
        ->except(['show'])
        ->names('admin.promo-items');
});

// Login-free email footer actions (FR-5, AD-5/AD-6/AD-13). Signed URLs scoped to
// the trip/user id; the signed GET only renders a confirmation page, the POST does
// the change (prefetch-safe). Throttled as defense-in-depth on top of the signature.
Route::middleware(['signed', 'throttle:20,1'])->group(function () {
    Route::get('email/trip/{trip}/end', [EmailAction::class, 'confirmEnd'])->name('email.trip.end');
    Route::post('email/trip/{trip}/end', [EmailAction::class, 'end'])->name('email.trip.end.post');

    Route::get('email/user/{user}/unsubscribe', [EmailAction::class, 'confirmUnsubscribe'])->name('email.unsubscribe');
    Route::post('email/user/{user}/unsubscribe', [EmailAction::class, 'unsubscribe'])->name('email.unsubscribe.post');

    // The RFC 8058 one-click POST target. Since 2026-07-02 (Story 9.9) no
    // email header references it — the custom List-Unsubscribe headers were
    // removed (MailerSend plan gate #MS42235). Kept live as the re-enable path
    // (deferred-work.md); still opts out any valid-signature POST.
    // CSRF-exempt — see bootstrap/app.php; signed + idempotent.
    Route::post('email/user/{user}/unsubscribe/one-click', [EmailAction::class, 'unsubscribeOneClick'])
        ->name('email.unsubscribe.one_click');

    // Feedback chips (FR-8): signed GET confirms, POST upserts the reaction
    // (last-reaction-wins, keyed on trip + the send_date query param).
    Route::get('email/trip/{trip}/feedback/{reaction}', [EmailAction::class, 'confirmFeedback'])
        ->whereIn('reaction', ['helped', 'not_helpful'])
        ->name('email.trip.feedback');
    Route::post('email/trip/{trip}/feedback/{reaction}', [EmailAction::class, 'feedback'])
        ->whereIn('reaction', ['helped', 'not_helpful'])
        ->name('email.trip.feedback.post');

    // Promo-click attribution (FR-18, AD-18): the one signed action that stays a
    // GET — reads-then-logs-then-forwards to Amazon. Mutates no app state (only
    // an idempotent promo_events append), so mail-client prefetch is harmless.
    Route::get('email/promo/{trip}/{slug}', [PromoRedirect::class, 'click'])->name('promo.click');
});

require __DIR__.'/auth.php';
