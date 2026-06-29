<?php

namespace App\Actions;

use App\Mail\WelcomeMail;
use App\Models\Trip;
use Illuminate\Support\Facades\Mail;

/**
 * Queue the one-time welcome for a trip (FR-9), honoring account-level opt-out
 * (AD-13). Fired when a trip becomes real-for-sending: at creation for an
 * already-confirmed owner, or at email confirmation for a new signup.
 */
class SendWelcomeEmail
{
    public function handle(Trip $trip): void
    {
        if ($trip->user->email_opted_out) {
            return;
        }

        Mail::to($trip->user->email)->queue(new WelcomeMail($trip));
    }
}
