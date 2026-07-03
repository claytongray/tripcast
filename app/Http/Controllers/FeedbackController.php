<?php

namespace App\Http\Controllers;

use App\Mail\FeedbackMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * General site feedback (Story 10.1): queues the user's free-text note to the
 * team inbox (mail from-address) with reply-to set to the sender. Email-only by
 * design — no table; the digest-reaction `feedback` table is a separate,
 * structured metric and must not absorb this. Per-user limiter mirrors the
 * dashboard sample action so the error lands calmly on the form field.
 */
class FeedbackController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $key = 'feedback:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'message' => "That's a lot of feedback in one sitting — thank you! Give it about an hour and send more.",
            ]);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'source' => ['required', 'in:dashboard,nav'],
        ]);

        RateLimiter::hit($key, 3600);

        Mail::to(config('mail.from.address'))->queue(new FeedbackMail(
            $user->email,
            $validated['message'],
            $validated['source'],
            $user->trips()->count(),
        ));

        return back();
    }
}
