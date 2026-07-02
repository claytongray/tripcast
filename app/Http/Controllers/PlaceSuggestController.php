<?php

namespace App\Http\Controllers;

use App\Services\Geocoding\PlaceAutocomplete;
use App\Services\Geocoding\PlaceSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Server proxy for destination autocomplete (FR-22, AD-1): the restricted
 * Google key stays server-side; the adapter returns an empty list on any
 * vendor failure, so valid input always gets 200 + a (possibly empty) list
 * and the client needs no error branch — the field degrades to free text.
 */
class PlaceSuggestController extends Controller
{
    public function __invoke(Request $request, PlaceAutocomplete $autocomplete): JsonResponse
    {
        // Explicit validator: this app renders JSON validation errors only for
        // api/* (bootstrap/app.php), and this XHR endpoint must 422 as JSON —
        // never redirect — so the client's silent-degradation contract holds.
        $validator = Validator::make($request->query(), [
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'token' => ['required', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            return response()->json(['suggestions' => []], 422);
        }

        $validated = $validator->validated();

        $suggestions = $autocomplete->suggest($validated['q'], $validated['token']);

        return response()->json([
            'suggestions' => array_map(fn (PlaceSuggestion $s): array => [
                'place_id' => $s->placeId,
                'label' => $s->label,
            ], $suggestions),
        ]);
    }
}
