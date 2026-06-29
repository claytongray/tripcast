<?php

use App\Models\LoginToken;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prune expired/consumed magic-link tokens (AD-6 convention).
Schedule::command('model:prune', ['--model' => [LoginToken::class]])
    ->daily()
    ->name('prune-login-tokens');

// The daily digest run at the fixed 09:00 America/New_York send clock (AD-2, AD-7).
Schedule::command('digests:send')
    ->dailyAt('09:00')
    ->timezone('America/New_York')
    ->name('send-daily-digests');
