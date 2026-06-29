<?php

use App\Http\Controllers\LandingController;
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

Route::middleware('auth')->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/auth.php';
