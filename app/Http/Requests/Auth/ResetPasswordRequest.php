<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rules\Password;

final class ResetPasswordRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normaliseEmail();
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:128'],
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'max:128'],
            'password_confirmation' => ['required', 'same:password'],
            'logout_other_devices' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'The password must be at least 8 characters.',
            'password_confirmation.same' => "Passwords don't match.",
        ];
    }
}
