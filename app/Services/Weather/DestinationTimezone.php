<?php

namespace App\Services\Weather;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves a destination's IANA timezone from coordinates via the Google Time
 * Zone API (the same key used for geocoding). WeatherKit needs a zone to align
 * daily highs to the destination's local day. Returns null on any failure so
 * callers persist only real zones and fall back to the config default for a
 * one-off fetch — a Google blip never fabricates a wrong stored zone.
 *
 * Minimal in Story 11.1 (resolve + cache + null-on-failure); Story 11.2 wires
 * persistence into `trips.destination_timezone` at trip creation.
 */
class DestinationTimezone
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/timezone/json';

    public function resolve(float $latitude, float $longitude): ?string
    {
        // No key configured (dev/CI, like the FakeGeocoder discipline) — degrade to
        // the caller's fallback rather than firing a real request per trip create.
        // In production a blank key is a misconfiguration worth surfacing.
        if (blank(config('services.google.geocoding_key'))) {
            if (app()->isProduction()) {
                Log::warning('destination timezone skipped — GOOGLE_GEOCODING_KEY is blank');
            }

            return null;
        }

        $key = 'tz:'.round($latitude, 3).','.round($longitude, 3);

        try {
            return Cache::remember($key, now()->addDays(30), function () use ($latitude, $longitude): ?string {
                // Short timeout: this runs synchronously in the trip-create request.
                $response = Http::timeout(3)->get(self::ENDPOINT, [
                    'location' => $latitude.','.$longitude,
                    'timestamp' => time(),
                    'key' => config('services.google.geocoding_key'),
                ]);

                $data = $response->json();

                if (($data['status'] ?? null) !== 'OK' || empty($data['timeZoneId'])) {
                    Log::warning('destination timezone unresolved', ['status' => $data['status'] ?? 'no-status']);

                    return null;
                }

                $zone = (string) $data['timeZoneId'];

                // Reject a zone PHP's tzdata doesn't know (Google can return newer
                // ids than the distro tzdata) — persisting it would throw in
                // setTimezone() and fail that trip's digest every day. Fall back.
                if (! in_array($zone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
                    Log::warning('destination timezone not a known IANA id', ['zone' => $zone]);

                    return null;
                }

                return $zone;
            });
        } catch (Throwable $e) {
            // Never let a transport OR cache-layer (Redis/predis) failure escape —
            // callers fall back to the config default rather than stranding a send.
            Log::warning('destination timezone resolve failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
