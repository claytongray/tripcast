<?php

use App\Http\Controllers\Auth\MagicLinkController;
use Illuminate\Support\Facades\Route;

// Passwordless magic-link authentication (AD-6).
Route::middleware('guest')->group(function () {
    Route::get('login', [MagicLinkController::class, 'create'])->name('login');
    Route::post('login', [MagicLinkController::class, 'store'])->name('login.store');
    Route::get('login/sent', [MagicLinkController::class, 'sent'])->name('login.sent');
});

// Consuming a link logs in even if a stale session exists, so it sits outside the guest group.
Route::get('auth/magic/{token}', [MagicLinkController::class, 'consume'])->name('magic.consume');

Route::post('logout', [MagicLinkController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
