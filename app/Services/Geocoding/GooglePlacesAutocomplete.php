<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Places API (New) autocomplete adapter (AD-1, FR-22). The only place
 * the vendor HTTP contract appears. The session token groups keystrokes for
 * per-session billing; the session is terminated by the Place Details call in
 * {@see GoogleGeocoder::resolvePlace()}.
 *
 * `(regions)` is the single allowed type collection covering the AC's
 * cities/regions (localities, admin areas, countries). Known tradeoff: it also
 * matches postal codes — acceptable; `(cities)` would drop regions entirely.
 */
class GooglePlacesAutocomplete implements PlaceAutocomplete
{
    private const ENDPOINT = 'https://places.googleapis.com/v1/places:autocomplete';

    private const MAX_SUGGESTIONS = 5;

    public function __construct(private string $apiKey) {}

    public function suggest(string $query, string $sessionToken): array
    {
        // Degradation is the contract (FR-22): any failure → empty list, and
        // the field behaves as plain free text. A short timeout keeps a slow
        // vendor from stalling the typing experience.
        try {
            $response = Http::timeout(3)
                ->withHeader('X-Goog-Api-Key', $this->apiKey)
                ->post(self::ENDPOINT, [
                    'input' => $query,
                    'sessionToken' => $sessionToken,
                    'includedPrimaryTypes' => ['(regions)'],
                ]);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $suggestions = [];

        foreach ($response->json('suggestions') ?? [] as $suggestion) {
            $placeId = $suggestion['placePrediction']['placeId'] ?? null;
            $label = $suggestion['placePrediction']['text']['text'] ?? null;

            if (! is_string($placeId) || ! is_string($label)) {
                continue;
            }

            $suggestions[] = new PlaceSuggestion($placeId, $label);

            if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $suggestions;
    }
}
