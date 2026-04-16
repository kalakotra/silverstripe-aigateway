<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Providers;

/**
 * Mittwald-hosted Open-LLM provider.
 *
 * This endpoint is OpenAI-compatible, so we reuse the OpenAI chat-completions
 * request/response handling and only override the base URL, slug, and API-key
 * validation rule.
 */
class MittwaldOpenLLMProvider extends OpenAIProvider
{
    private const BASE_URL = 'https://llm.aihosting.mittwald.de/v1';

    protected function getEndpointUrl(): string
    {
        return self::BASE_URL . '/chat/completions';
    }

    public function getProviderSlug(): string
    {
        return 'mittwald-open-llm';
    }

    /**
     * Mittwald self-hosted deployments may use non-OpenAI key formats.
     */
    public function validateApiKey(string $apiKey): bool
    {
        return strlen(trim($apiKey)) > 10;
    }
}