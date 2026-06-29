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
// The emailed link is a GET that only *shows* a confirmation screen — it never consumes the
// token. A CSRF-protected POST does the actual consume + login. This keeps mail-security
// scanners, link unfurlers, and browser prefetch (all GET) from burning the single-use token,
// and prevents login-CSRF (an attacker cannot auto-submit the cross-site POST).
Route::get('auth/magic/{token}', [MagicLinkController::class, 'confirm'])->name('magic.consume');
Route::post('auth/magic/{token}', [MagicLinkController::class, 'consume'])->name('magic.consume.store');

Route::post('logout', [MagicLinkController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
