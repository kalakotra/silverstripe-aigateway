<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Providers;

/**
 * OpenLLM provider hosted on Mittwald.
 *
 * Endpoint is OpenAI-compatible Chat Completions API.
 */
class OpenLLMProvider extends OpenAIProvider
{
    private const ENDPOINT = 'https://llm.aihosting.mittwald.de/v1/chat/completions';

    protected function getEndpointUrl(): string
    {
        return self::ENDPOINT;
    }

    public function getProviderSlug(): string
    {
        return 'openllm';
    }

    /**
     * OpenLLM deployments can use non-OpenAI key formats.
     */
    public function validateApiKey(string $apiKey): bool
    {
        return strlen(trim($apiKey)) > 10;
    }
}
