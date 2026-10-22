<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

final class EmailLookupRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normaliseEmail();
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:254'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.email' => 'Enter a valid email address.',
        ];
    }
}
