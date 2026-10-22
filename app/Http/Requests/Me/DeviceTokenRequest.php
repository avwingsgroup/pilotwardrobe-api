<?php

declare(strict_types=1);

namespace App\Http\Requests\Me;

use App\Http\Requests\ApiRequest;

final class DeviceTokenRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:20', 'max:4096'],
            'platform' => ['required', 'in:ios,android,web'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ];
    }
}
