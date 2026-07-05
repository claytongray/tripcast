<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendTripDigestRequest;
use App\Models\AdminEmailSend;
use App\Models\Trip;
use App\Services\Digest\AdminDigestSender;
use App\Services\Digest\SuppressedRecipientException;
use Illuminate\Http\RedirectResponse;

/**
 * On-demand digest trigger (mutating admin surface). Registered inside the single
 * `['auth','can:admin']->prefix('admin')` group (AD-12). Synchronous so the admin
 * gets an immediate pass/fail flash; the send itself is fully out-of-band
 * (`admin_email_sends` only — never `email_logs`, never a PromoEvent).
 */
class TripDigestController extends Controller
{
    public function send(SendTripDigestRequest $request, Trip $trip, AdminDigestSender $sender): RedirectResponse
    {
        $recipient = $request->validated('recipient');
        $admin = $request->user();

        try {
            $send = $recipient === AdminEmailSend::RECIPIENT_OWNER
                ? $sender->sendToOwner($trip, $admin)
                : $sender->sendToAdmin($trip, $admin);
        } catch (SuppressedRecipientException $e) {
            return back()->with('error', "Can't send to owner: {$e->getMessage()}.");
        }

        if ($send->status === AdminEmailSend::STATUS_FAILED) {
            return back()->with('error', "Send failed: {$send->failure_reason}.");
        }

        $message = $recipient === AdminEmailSend::RECIPIENT_OWNER
            ? "Sent to owner ({$send->recipient_email})."
            : "Sent to you ({$send->recipient_email}).";

        return back()->with('status', $message);
    }
}
