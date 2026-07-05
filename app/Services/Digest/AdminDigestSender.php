<?php

namespace App\Services\Digest;

use App\Models\AdminEmailSend;
use App\Models\Trip;
use App\Models\User;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WeatherProviderFailedException;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Drives one admin-triggered digest send end to end, synchronously so the admin
 * gets an immediate pass/fail. Uses the shared DigestComposer for assembly and
 * records the outcome only in `admin_email_sends` — never `email_logs` (AD-3),
 * never a PromoEvent (out-of-band; no metric distortion). Send-to-owner honors
 * owner suppression (AD-11/AD-13); send-to-me is a preview and is always allowed.
 */
class AdminDigestSender
{
    public function __construct(
        private readonly WeatherProvider $weather,
        private readonly DigestComposer $composer,
    ) {}

    /**
     * Force-send a real digest to the trip owner (bypassing the same-day dedup
     * lock), refusing if the owner is suppressed.
     *
     * @throws SuppressedRecipientException
     */
    public function sendToOwner(Trip $trip, User $admin): AdminEmailSend
    {
        $this->assertDeliverable($trip->user);

        return $this->send($trip, $admin, AdminEmailSend::RECIPIENT_OWNER, $trip->user->email);
    }

    /**
     * Preview-send the digest to the acting admin's own address. Always allowed.
     */
    public function sendToAdmin(Trip $trip, User $admin): AdminEmailSend
    {
        return $this->send($trip, $admin, AdminEmailSend::RECIPIENT_ADMIN, $admin->email);
    }

    /**
     * @throws SuppressedRecipientException
     */
    private function assertDeliverable(User $owner): void
    {
        if (! $owner->hasConfirmedEmail()) {
            throw new SuppressedRecipientException('the owner has not confirmed their email');
        }

        if ($owner->email_opted_out) {
            throw new SuppressedRecipientException('the owner has opted out of all email');
        }
    }

    private function send(Trip $trip, User $admin, string $recipient, string $email): AdminEmailSend
    {
        $sendDate = now('America/New_York')->toDateString();

        try {
            $forecast = $this->weather->fetchForecast(
                $trip->latitude,
                $trip->longitude,
                $trip->destination_timezone,
            );
        } catch (WeatherProviderFailedException $e) {
            return $this->record($trip, $admin, $recipient, $email, AdminEmailSend::STATUS_FAILED, 'weather: '.$e->getMessage());
        }

        // Compose but ignore the promo: admin sends record no impression (AD-18).
        $composed = $this->composer->compose($trip, $forecast->toArray(), $sendDate);

        try {
            Mail::to($email)->send($composed->mail);
        } catch (Throwable $e) {
            return $this->record($trip, $admin, $recipient, $email, AdminEmailSend::STATUS_FAILED, 'delivery: '.$e->getMessage());
        }

        return $this->record($trip, $admin, $recipient, $email, AdminEmailSend::STATUS_SENT, null);
    }

    private function record(Trip $trip, User $admin, string $recipient, string $email, string $status, ?string $reason): AdminEmailSend
    {
        return AdminEmailSend::create([
            'trip_id' => $trip->id,
            'admin_user_id' => $admin->id,
            'recipient' => $recipient,
            'recipient_email' => $email,
            'status' => $status,
            'failure_reason' => $reason,
        ]);
    }
}
