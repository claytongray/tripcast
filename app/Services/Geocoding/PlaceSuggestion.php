<?php

namespace App\Services\Geocoding;

/**
 * One autocomplete prediction (FR-22): the vendor place identifier and the
 * human label shown in the listbox.
 */
final class PlaceSuggestion
{
    public function __construct(
        public string $placeId,
        public string $label,
    ) {}
}
