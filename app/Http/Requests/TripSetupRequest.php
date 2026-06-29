<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the landing-hero trip-setup form (FR-1, UX-DR3).
 *
 * No auth required — the form submits before any account exists. Dates are
 * timezone-naive and validated against the America/New_York calendar date,
 * the fixed send clock (AD-7).
 */
class TripSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $today = now('America/New_York')->toDateString();

        return [
            'destination' => ['required', 'string', 'max:255'],
            'departure_date' => ['required', 'date', 'after_or_equal:'.$today],
            'return_date' => ['required', 'date', 'after_or_equal:departure_date'],
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
            'departure_date.date' => 'Pick your departure date.',
            'return_date.required' => 'Pick your return date.',
            'return_date.date' => 'Pick your return date.',
        ];
    }
}
