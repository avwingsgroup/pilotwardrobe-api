<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A business error that renders as the contract's error envelope:
 * `{ success: false, message, code, errors?, meta? }`.
 *
 * `message` is shown to end users, so it must never carry internals.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly ?array $errors = null,
        public readonly ?array $meta = null,
        public readonly ?int $status = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function forbidden(string $message = 'This action is unauthorized.'): self
    {
        return new self(ErrorCode::FORBIDDEN, $message);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(ErrorCode::NOT_FOUND, $message);
    }

    public static function conflict(string $message): self
    {
        return new self(ErrorCode::CONFLICT, $message);
    }

    public static function invalidTransition(string $message = 'This action is not allowed in the current state.'): self
    {
        return new self(ErrorCode::INVALID_STATE_TRANSITION, $message);
    }

    public static function kycRequired(string $message = 'Verify your identity to continue.'): self
    {
        return new self(ErrorCode::KYC_REQUIRED, $message);
    }

    public static function tokenExpired(string $message = 'This link has expired.'): self
    {
        return new self(ErrorCode::TOKEN_EXPIRED, $message);
    }

    public static function sessionExpired(string $message = 'Your session has expired. Please sign in again.'): self
    {
        return new self(ErrorCode::SESSION_EXPIRED, $message);
    }

    public static function gatewayUnavailable(string $message = 'This payment method is temporarily unavailable. Please try another.'): self
    {
        return new self(ErrorCode::GATEWAY_UNAVAILABLE, $message);
    }

    public static function paymentFailed(string $message = 'The payment could not be completed.'): self
    {
        return new self(ErrorCode::PAYMENT_FAILED, $message);
    }

    /**
     * Field-level validation failure raised from business rules (after FormRequest validation).
     *
     * @param  array<string, string|list<string>>  $errors
     * @param  array<string, mixed>|null  $meta
     */
    public static function validation(array $errors, string $message = 'The submitted data is invalid.', ?array $meta = null): self
    {
        $normalised = array_map(fn ($m) => is_array($m) ? array_values($m) : [$m], $errors);

        return new self(ErrorCode::VALIDATION_ERROR, $message, $normalised, $meta);
    }

    public function httpStatus(): int
    {
        return $this->status ?? $this->errorCode->httpStatus();
    }

    public function render(): JsonResponse
    {
        $body = ['success' => false, 'message' => $this->getMessage(), 'code' => $this->errorCode->value];
        if ($this->errors !== null) {
            $body['errors'] = $this->errors;
        }
        if ($this->meta !== null) {
            $body['meta'] = $this->meta;
        }

        return response()->json($body, $this->httpStatus(), $this->headers);
    }
}
