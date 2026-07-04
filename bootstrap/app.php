<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Guests hitting the dashboard land on the public homepage (which carries
        // its own sign-in) rather than the bare login page — so a dashboard URL
        // shared from someone's phone opens the marketing site for the recipient.
        // Every other guarded route keeps the default login redirect.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->routeIs('dashboard') ? route('home') : route('login'),
        );

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The List-Unsubscribe-Post one-click target is a signed, idempotent POST
        // sent directly by the mail client (no session, no CSRF token). Scope the
        // exception narrowly to that one path; the signature is its protection.
        $middleware->validateCsrfTokens(except: [
            'email/*/unsubscribe/one-click',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
