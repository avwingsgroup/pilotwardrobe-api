<?php

declare(strict_types=1);

namespace App\Http\Requests\Me;

use App\Http\Requests\ApiRequest;

final class FitKitProfileRequest extends ApiRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $measure = ['nullable', 'string', 'max:20'];

        return [
            'name' => [$required, 'string', 'max:60'],
            'for_person' => ['nullable', 'string', 'max:60'],
            'is_default' => ['nullable', 'boolean'],
            'unit' => ['sometimes', 'in:us_in,metric'],
            'jacket' => $measure,
            'collar' => $measure,
            'sleeve' => $measure,
            'waist' => $measure,
            'inseam' => $measure,
            'shoe' => $measure,
            'bag' => $measure,
            'apparel' => $measure,
            'preferences' => ['nullable', 'array'],
            'preferences.filter_search_by_size' => ['nullable', 'boolean'],
            'preferences.warn_outside_measurements' => ['nullable', 'boolean'],
            'preferences.use_at_checkout' => ['nullable', 'boolean'],
        ];
    }
}
