<?php

namespace App\Actions;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single trip-creation decision point (AD-10, AD-15).
 *
 * Upserts the User (create-or-match by case-insensitive email) and inserts the
 * Trip in one DB-only transaction — no external calls inside (mail/geocode run
 * outside, before/after). A Trip never exists without an owner (AD-10) or the
 * coordinates resolved earlier (AD-8).
 *
 * The free-tier cap (AD-15) will be enforced here in Story 3.3 — every add path
 * routes through this action.
 */
class CreateTrip
{
    public function __construct(private SendWelcomeEmail $sendWelcomeEmail) {}

    /**
     * @param  array{destination: string, departure_date: string, return_date: string, canonical_place_name: string, latitude: float, longitude: float, temperature_unit?: string|null}  $tripDetails
     */
    public function handle(string $email, array $tripDetails): Trip
    {
        $email = Str::lower(trim($email));

        $trip = DB::transaction(function () use ($email, $tripDetails): Trip {
            // The temperature preference is set only when the account is born;
            // an existing user keeps their own (firstOrCreate's values apply on
            // create only). Defaults to Fahrenheit.
            $user = User::firstOrCreate(['email' => $email], [
                'temperature_unit' => $tripDetails['temperature_unit'] ?? User::UNIT_FAHRENHEIT,
            ]);

            return $user->trips()->create([
                'destination_raw' => $tripDetails['destination'],
                'canonical_place_name' => $tripDetails['canonical_place_name'],
                'latitude' => $tripDetails['latitude'],
                'longitude' => $tripDetails['longitude'],
                'departure_date' => $tripDetails['departure_date'],
                'return_date' => $tripDetails['return_date'],
                'status' => Trip::STATUS_ACTIVE,
            ]);
        });

        // Welcome only an already-confirmed owner now (the logged-in add-trip
        // path). A brand-new, unconfirmed signup is welcomed at email
        // confirmation instead (MagicLinkController@consume) — so no email ever
        // reaches an unconfirmed address except the activation link (AD-6, FR-9).
        if ($trip->user->hasConfirmedEmail()) {
            $this->sendWelcomeEmail->handle($trip);
        }

        return $trip;
    }
}
