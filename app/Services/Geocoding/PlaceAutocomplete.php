<?php

namespace App\Services\Geocoding;

/**
 * Place-autocomplete port (AD-1, FR-22). Suggestions are an affordance, not a
 * dependency: implementations return an empty list on any failure so the
 * destination field degrades to plain free text (never an exception).
 */
interface PlaceAutocomplete
{
    /**
     * As-you-type place suggestions for a partial destination query.
     *
     * @return list<PlaceSuggestion>
     */
    public function suggest(string $query, string $sessionToken): array;
}
