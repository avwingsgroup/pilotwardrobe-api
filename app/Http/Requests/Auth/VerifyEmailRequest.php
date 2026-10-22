<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

final class VerifyEmailRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normaliseEmail();
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:254'],
            'code' => ['required', 'digits:4'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.digits' => 'Enter the 4-digit code from the email.',
            'code.required' => 'Enter the 4-digit code from the email.',
        ];
    }
}
