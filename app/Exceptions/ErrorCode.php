<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Machine-readable error codes from the contract (§1.5). The SPA switches on these. */
enum ErrorCode: string
{
    case UNKNOWN = 'UNKNOWN';
    case UNAUTHENTICATED = 'UNAUTHENTICATED';
    case FORBIDDEN = 'FORBIDDEN';
    case KYC_REQUIRED = 'KYC_REQUIRED';
    case EMAIL_NOT_VERIFIED = 'EMAIL_NOT_VERIFIED';
    case NOT_FOUND = 'NOT_FOUND';
    case CONFLICT = 'CONFLICT';
    case INVALID_STATE_TRANSITION = 'INVALID_STATE_TRANSITION';
    case TOKEN_EXPIRED = 'TOKEN_EXPIRED';
    case SESSION_EXPIRED = 'SESSION_EXPIRED';
    case VALIDATION_ERROR = 'VALIDATION_ERROR';
    case ACCOUNT_LOCKED = 'ACCOUNT_LOCKED';
    case RATE_LIMITED = 'RATE_LIMITED';
    case PAYMENT_FAILED = 'PAYMENT_FAILED';
    case GATEWAY_UNAVAILABLE = 'GATEWAY_UNAVAILABLE';
    case MAINTENANCE = 'MAINTENANCE';
    case SERVER_ERROR = 'SERVER_ERROR';

    public function httpStatus(): int
    {
        return match ($this) {
            self::UNKNOWN => 400,
            self::UNAUTHENTICATED => 401,
            self::FORBIDDEN, self::KYC_REQUIRED, self::EMAIL_NOT_VERIFIED => 403,
            self::NOT_FOUND => 404,
            self::CONFLICT, self::INVALID_STATE_TRANSITION => 409,
            self::TOKEN_EXPIRED => 410,
            self::SESSION_EXPIRED => 419,
            self::VALIDATION_ERROR, self::PAYMENT_FAILED => 422,
            self::ACCOUNT_LOCKED => 423,
            self::RATE_LIMITED => 429,
            self::GATEWAY_UNAVAILABLE, self::MAINTENANCE => 503,
            self::SERVER_ERROR => 500,
        };
    }
}
