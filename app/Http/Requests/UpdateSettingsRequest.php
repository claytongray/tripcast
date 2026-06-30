<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the settings page (Spec A). The account holds a single temperature
 * unit the digest renders; this is the only field a user may change here. Email,
 * plan, and is_admin are intentionally never accepted.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'temperature_unit' => [
                'required',
                Rule::in([User::UNIT_FAHRENHEIT, User::UNIT_CELSIUS]),
            ],
        ];
    }
}
