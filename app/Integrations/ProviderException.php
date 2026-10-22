<?php

declare(strict_types=1);

namespace App\Integrations;

use RuntimeException;
use Throwable;

/**
 * Failure talking to a third-party provider, classified so callers know whether a retry
 * can help: `transient` (timeouts, 5xx, rate limits) vs `permanent` (bad request,
 * auth/config error, declined). Messages are for logs only — never show them to users.
 */
final class ProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        string $message,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct("[{$provider}] {$message}", 0, $previous);
    }

    public static function transient(string $provider, string $message, ?Throwable $previous = null, ?int $status = null): self
    {
        return new self($provider, $message, true, $status, $previous);
    }

    public static function permanent(string $provider, string $message, ?Throwable $previous = null, ?int $status = null): self
    {
        return new self($provider, $message, false, $status, $previous);
    }

    /** Classifies an HTTP status from a provider response. */
    public static function fromStatus(string $provider, int $status, string $message): self
    {
        $retryable = $status >= 500 || $status === 429 || $status === 408;

        return new self($provider, "HTTP {$status}: {$message}", $retryable, $status);
    }
}
