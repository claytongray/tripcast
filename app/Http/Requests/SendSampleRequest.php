<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the public "send me a sample" form. Email only — the destination is
 * fixed server-side (config) in this MVP.
 */
class SendSampleRequest extends FormRequest
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
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => "Enter your email and we\u{2019}ll send a sample.",
            'email.email' => "That email doesn\u{2019}t look right.",
        ];
    }
}
