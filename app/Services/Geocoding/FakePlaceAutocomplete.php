<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Str;

/**
 * Deterministic autocomplete for local dev (before/without a real key) and
 * tests. Suggests the same places {@see FakeGeocoder} resolves; the shared
 * `fake-*` ids are what {@see FakeGeocoder::resolvePlace()} recognizes.
 */
class FakePlaceAutocomplete implements PlaceAutocomplete
{
    /** @var array<string, string> */
    private array $known = [
        'edinburgh' => 'Edinburgh, United Kingdom',
        'paris' => 'Paris, France',
        'tokyo' => 'Tokyo, Japan',
    ];

    public function suggest(string $query, string $sessionToken): array
    {
        $needle = Str::lower(trim($query));

        if ($needle === '') {
            return [];
        }

        $suggestions = [];

        foreach ($this->known as $key => $label) {
            if (str_starts_with($key, $needle)) {
                $suggestions[] = new PlaceSuggestion("fake-{$key}", $label);
            }
        }

        return $suggestions;
    }
}
