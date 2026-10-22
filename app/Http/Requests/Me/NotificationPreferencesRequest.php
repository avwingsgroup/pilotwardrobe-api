<?php

declare(strict_types=1);

namespace App\Http\Requests\Me;

use App\Enums\NotificationCategory;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class NotificationPreferencesRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        // Accept either a bare array (the GET shape) or `{ preferences: [...] }`.
        if (! $this->has('preferences') && array_is_list($this->all())) {
            $this->replace(['preferences' => $this->all()]);
        }
    }

    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'max:20'],
            'preferences.*.category' => ['required', Rule::in(array_map(fn ($c) => $c->value, NotificationCategory::cases()))],
            'preferences.*.email' => ['nullable', 'boolean'],
            'preferences.*.push' => ['nullable', 'boolean'],
            'preferences.*.sms' => ['nullable', 'boolean'],
            'preferences.*.whatsapp' => ['nullable', 'boolean'],
        ];
    }
}
