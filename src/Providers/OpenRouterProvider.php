<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Providers;

/**
 * OpenRouter provider.
 *
 * Endpoint is OpenAI-compatible Chat Completions API.
 */
class OpenRouterProvider extends OpenAIProvider
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    protected function getEndpointUrl(): string
    {
        return self::ENDPOINT;
    }

    public function getProviderSlug(): string
    {
        return 'openrouter';
    }

    /**
     * OpenRouter keys are typically prefixed with "sk-or-".
     * We allow a permissive fallback for self-hosted gateway compatibility.
     */
    public function validateApiKey(string $apiKey): bool
    {
        $trimmed = trim($apiKey);

        if (str_starts_with($trimmed, 'sk-or-')) {
            return strlen($trimmed) >= 20;
        }

        return strlen($trimmed) > 10;
    }
}