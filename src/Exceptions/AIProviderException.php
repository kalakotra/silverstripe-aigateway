<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Exceptions;

use RuntimeException;

/**
 * Thrown by any AIProviderInterface implementation when an API call fails.
 *
 * Wraps the underlying HTTP / parsing exception and enriches it with
 * provider-specific context so the Gateway Service can log accurately.
 */
final class AIProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $providerSlug,
        private readonly int $httpStatusCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatusCode, $previous);
    }

    public function getProviderSlug(): string
    {
        return $this->providerSlug;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
