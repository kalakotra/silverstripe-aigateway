<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Providers;

use Kalakotra\AIGateway\Exceptions\AIProviderException;
use Kalakotra\AIGateway\Interfaces\AIResponseDTO;

/**
 * OpenAI provider — targets the Chat Completions API.
 *
 * API reference: https://platform.openai.com/docs/api-reference/chat/create
 *
 * Request shape:
 * {
 *   "model":    "gpt-4o",
 *   "messages": [
 *     { "role": "system",    "content": "<system prompt>" },
 *     { "role": "user",      "content": "<user prompt>"   }
 *   ],
 *   "temperature": 1.0,
 *   "max_tokens":  1024
 * }
 *
 * Response shape (relevant fields):
 * {
 *   "choices": [{ "message": { "content": "..." } }],
 *   "model":   "gpt-4o-2024-08-06",
 *   "usage":   { "prompt_tokens": 12, "completion_tokens": 45, "total_tokens": 57 }
 * }
 */
class OpenAIProvider extends AbstractAIProvider
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    // =========================================================================
    // AbstractAIProvider — implementation
    // =========================================================================

    protected function getEndpointUrl(): string
    {
        return self::ENDPOINT;
    }

    /**
     * @return array<string, string>
     */
    protected function getRequestHeaders(): array
    {
        return [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];
    }

    /**
     * @param  string               $prompt
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function buildRequestPayload(string $prompt, array $options): array
    {
        $messages = [];

        // Prepend an optional system message if provided.
        if (!empty($options['system']) && is_string($options['system'])) {
            $messages[] = [
                'role'    => 'system',
                'content' => $options['system'],
            ];
        }

        $messages[] = [
            'role'    => 'user',
            'content' => $prompt,
        ];

        $payload = [
            'model'    => $this->modelName,
            'messages' => $messages,
        ];

        // Apply optional call-level overrides.
        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        if (isset($options['max_tokens']) && is_int($options['max_tokens'])) {
            // Reasoning models (o1, o3, o4 family) require max_completion_tokens.
            // Standard models (gpt-*) use the legacy max_tokens parameter.
            $tokenKey = preg_match('/^o\d/', $this->modelName) ? 'max_completion_tokens' : 'max_tokens';
            $payload[$tokenKey] = $options['max_tokens'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed> $raw
     * @throws AIProviderException  When the response shape is unexpected.
     */
    protected function parseResponse(
        array $raw,
        string $providerSlug,
        string $model,
        float $latencyMs,
    ): AIResponseDTO {
        $content = $raw['choices'][0]['message']['content'] ?? null;

        if (!is_string($content)) {
            throw new AIProviderException(
                message: sprintf(
                    '[openai] Unexpected response shape — "choices[0].message.content" missing. '
                    . 'Finish reason: %s.',
                    $raw['choices'][0]['finish_reason'] ?? 'unknown'
                ),
                providerSlug: $providerSlug,
                httpStatusCode: 200,
            );
        }

        // OpenAI returns the actual model version used (e.g. 'gpt-4o-2024-08-06').
        // We prefer that over the requested alias.
        $resolvedModel = $raw['model'] ?? $model;

        return new AIResponseDTO(
            content: $content,
            model: $resolvedModel,
            providerSlug: $providerSlug,
            inputTokens: (int) ($raw['usage']['prompt_tokens']     ?? 0),
            outputTokens: (int) ($raw['usage']['completion_tokens'] ?? 0),
            latencyMs: $latencyMs,
            rawResponse: $raw,
        );
    }

    // =========================================================================
    // AIProviderInterface
    // =========================================================================

    public function getProviderSlug(): string
    {
        return 'openai';
    }

    /**
     * OpenAI keys always start with 'sk-' and are at least 40 characters long.
     */
    public function validateApiKey(string $apiKey): bool
    {
        return str_starts_with(trim($apiKey), 'sk-') && strlen(trim($apiKey)) >= 40;
    }
}
