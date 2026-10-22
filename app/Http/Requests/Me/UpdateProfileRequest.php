<?php

declare(strict_types=1);

namespace App\Http\Requests\Me;

use App\Http\Requests\ApiRequest;
use App\Support\Money\Currency;
use Illuminate\Validation\Rule;

final class UpdateProfileRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalisePhone('phone');
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:80'],
            'last_name' => ['sometimes', 'required', 'string', 'max:80'],
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9_]+$/'],
            'phone' => ['sometimes', 'nullable', 'string', self::phoneRule()],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'currency' => ['sometimes', 'required', Rule::in(Currency::codes())],
            'locale' => ['sometimes', 'required', 'string', 'regex:/^[a-z]{2}(-[A-Z]{2})?$/'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'avatar_media_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'cover_media_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:80'],
            'aircraft_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'state' => ['sometimes', 'nullable', 'string', 'max:80'],
            'dispatch_time' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return ['username.regex' => 'Use letters, numbers and underscores only.'];
    }
}
