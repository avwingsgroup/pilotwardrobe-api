<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

final class TwoFactorRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:20'],
            'method' => ['nullable', 'in:totp,sms,backup_code'],
            'trust_device' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Enter your verification code.',
        ];
    }
}
