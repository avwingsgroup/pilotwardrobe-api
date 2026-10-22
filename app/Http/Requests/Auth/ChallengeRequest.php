<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

final class ChallengeRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'max:64'],
        ];
    }
}
