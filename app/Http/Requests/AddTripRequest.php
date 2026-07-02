<?php

namespace App\Http\Requests;

use App\Actions\CreateTrip;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the dashboard add-trip panel (FR-12, Story 3.2).
 *
 * Authenticated counterpart of {@see TripSetupRequest}: a confirmed user adds a
 * trip directly (no email-capture step). Dates are timezone-naive and validated
 * against the America/New_York calendar date, the fixed send clock (AD-7). No
 * temperature_unit — the account already holds the preference.
 */
class AddTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $today = now('America/New_York')->toDateString();

        return [
            'destination' => ['required', 'string', 'max:255'],
            'departure_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'return_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:departure_date'],
            // Autocomplete selection (FR-22): optional exact-resolution hints.
            // Absent or unresolvable → plain text geocoding, unchanged.
            'place_id' => ['nullable', 'string', 'max:512'],
            'session_token' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * The validated trip fields as a precise, typed shape for {@see CreateTrip}.
     *
     * @return array{destination: string, departure_date: string, return_date: string}
     */
    public function tripDetails(): array
    {
        return [
            'destination' => (string) $this->string('destination'),
            'departure_date' => (string) $this->string('departure_date'),
            'return_date' => (string) $this->string('return_date'),
        ];
    }

    /**
     * Locked microcopy (EXPERIENCE.md Voice & Tone) — use verbatim.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destination.required' => 'Where are you headed?',
            'departure_date.after_or_equal' => "That date's already passed — pick a future trip.",
            'return_date.after_or_equal' => 'Return is before departure — check the dates.',
            'departure_date.required' => 'Pick your departure date.',
            'departure_date.date_format' => 'Pick your departure date.',
            'return_date.required' => 'Pick your return date.',
            'return_date.date_format' => 'Pick your return date.',
        ];
    }
}
