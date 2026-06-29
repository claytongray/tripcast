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
