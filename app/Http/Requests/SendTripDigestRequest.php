<?php

namespace App\Http\Requests;

use App\Models\AdminEmailSend;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an admin's on-demand digest trigger. Defense-in-depth: `authorize()`
 * re-checks the admin Gate on top of the route group's `can:admin` (AD-12).
 */
class SendTripDigestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipient' => ['required', Rule::in([AdminEmailSend::RECIPIENT_OWNER, AdminEmailSend::RECIPIENT_ADMIN])],
        ];
    }
}
