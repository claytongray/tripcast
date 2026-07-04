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
        $key = 'tz:'.round($latitude, 3).','.round($longitude, 3);

        try {
            return Cache::remember($key, now()->addDays(30), function () use ($latitude, $longitude): ?string {
                $response = Http::timeout(8)->get(self::ENDPOINT, [
                    'location' => $latitude.','.$longitude,
                    'timestamp' => time(),
                    'key' => config('services.google.geocoding_key'),
                ]);

                $data = $response->json();

                if (($data['status'] ?? null) !== 'OK' || empty($data['timeZoneId'])) {
                    Log::warning('destination timezone unresolved', ['status' => $data['status'] ?? 'no-status']);

                    return null;
                }

                return (string) $data['timeZoneId'];
            });
        } catch (Throwable $e) {
            // Never let a transport OR cache-layer (Redis/predis) failure escape —
            // callers fall back to the config default rather than stranding a send.
            Log::warning('destination timezone resolve failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
