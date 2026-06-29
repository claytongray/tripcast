<?php

namespace App\Actions;

use App\Mail\WelcomeMail;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
    /**
     * @param  array{destination: string, departure_date: string, return_date: string, canonical_place_name: string, latitude: float, longitude: float}  $tripDetails
     */
    public function handle(string $email, array $tripDetails): Trip
    {
        $email = Str::lower(trim($email));

        $trip = DB::transaction(function () use ($email, $tripDetails): Trip {
            $user = User::firstOrCreate(['email' => $email]);

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

        // One-time welcome (FR-9, AD-11), queued AFTER commit (outside the
        // transaction, AD-10), suppressed for opted-out owners (AD-13).
        if (! $trip->user->email_opted_out) {
            Mail::to($trip->user->email)->queue(new WelcomeMail($trip));
        }

        return $trip;
    }
}
