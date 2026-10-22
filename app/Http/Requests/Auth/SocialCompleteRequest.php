<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

final class SocialCompleteRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'pending_token' => ['required', 'string', 'max:128'],
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9_]+$/'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'account_type' => ['nullable', 'in:user,aviator'],
            'terms_accepted' => ['accepted'],
            'marketing_opt_in' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'terms_accepted.accepted' => 'You must accept the terms.',
            'username.regex' => 'Use letters, numbers and underscores only.',
        ];
    }
}
