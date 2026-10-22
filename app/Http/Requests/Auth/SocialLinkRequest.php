<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

final class SocialLinkRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'pending_token' => ['required_without:link_token', 'nullable', 'string', 'max:128'],
            'link_token' => ['nullable', 'string', 'max:128'],
            'password' => ['required', 'string', 'max:128'],
        ];
    }
}
