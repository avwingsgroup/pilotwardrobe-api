<?php

declare(strict_types=1);

namespace App\Http\Requests\Me;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rules\Password;

final class ChangePasswordRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            // Social-only accounts have no current password and may set one.
            'current_password' => [$this->user()?->password ? 'required' : 'nullable', 'string', 'max:128'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'max:128', 'different:current_password'],
            'password_confirmation' => ['required', 'same:password'],
            'logout_other_devices' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'The password must be at least 8 characters.',
            'password.different' => 'Choose a new password that differs from the current one.',
            'password_confirmation.same' => "Passwords don't match.",
        ];
    }
}
