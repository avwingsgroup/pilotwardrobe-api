<?php

declare(strict_types=1);

namespace App\Http\Requests\Me;

use App\Http\Requests\ApiRequest;

final class AddressRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalisePhone('phone');
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'label' => ['nullable', 'string', 'max:60'],
            'full_name' => [$required, 'string', 'max:120'],
            'phone' => [$required, 'string', self::phoneRule()],
            'line1' => [$required, 'string', 'max:200'],
            'line2' => ['nullable', 'string', 'max:200'],
            'city' => [$required, 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_code' => [$required, 'string', 'size:2', 'alpha'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid phone number including the country code.'];
    }
}
