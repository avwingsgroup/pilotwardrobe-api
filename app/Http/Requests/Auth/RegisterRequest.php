<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normaliseEmail();
        $this->normalisePhone('phone');
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:254', 'unique_ci:users,email'],
            'phone' => ['nullable', 'string', self::phoneRule()],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'max:128'],
            'password_confirmation' => ['required', 'same:password'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'account_type' => ['nullable', 'in:user,aviator'],
            'terms_accepted' => ['accepted'],
            'marketing_opt_in' => ['nullable', 'boolean'],
            'invite_token' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique_ci' => 'An account with this email already exists.',
            'password.min' => 'The password must be at least 8 characters.',
            'password_confirmation.same' => "Passwords don't match.",
            'password_confirmation.required' => 'Confirm your password.',
            'terms_accepted.accepted' => 'You must accept the terms.',
            'phone.regex' => 'Enter a valid phone number including the country code.',
        ];
    }
}
