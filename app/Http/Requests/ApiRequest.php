<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for API form requests. Authorisation is enforced by route middleware and
 * policies, so `authorize()` defaults to true; validation errors render as the
 * contract's 422 envelope via ApiExceptionRenderer.
 */
abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Normalises phone numbers to E.164-ish form (digits with optional leading +). */
    protected function normalisePhone(string $key): void
    {
        $value = $this->input($key);
        if (is_string($value) && $value !== '') {
            $this->merge([$key => preg_replace('/[\s\-().]/', '', $value)]);
        }
    }

    /** Lower-cases and trims an email field. */
    protected function normaliseEmail(string $key = 'email'): void
    {
        $value = $this->input($key);
        if (is_string($value)) {
            $this->merge([$key => mb_strtolower(trim($value))]);
        }
    }

    public static function phoneRule(): string
    {
        return 'regex:/^\+?[1-9]\d{7,14}$/';
    }
}
