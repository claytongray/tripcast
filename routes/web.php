<?php

use App\Http\Controllers\LandingController;
use Illuminate\Support\Facades\Route;

// Public landing hero + inline trip-setup form (FR-1). No auth: the form
// submits before any account exists, for guests and logged-in users alike.
Route::get('/', [LandingController::class, 'show'])->name('home');
Route::post('/', [LandingController::class, 'store'])->name('trip-setup.store');

// Placeholder next step; Story 1.3 replaces it with the geocoding confirm step.
Route::get('trip', [LandingController::class, 'tripDetail'])->name('trip.detail');

Route::middleware('auth')->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/auth.php';
