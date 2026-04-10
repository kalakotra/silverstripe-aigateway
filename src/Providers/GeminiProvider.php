<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Providers;

use Kalakotra\AIGateway\Exceptions\AIProviderException;
use Kalakotra\AIGateway\Interfaces\AIResponseDTO;

/**
 * Google Gemini provider — targets the Generative Language generateContent API.
 *
 * API reference: https://ai.google.dev/api/generate-content
 *
 * Authentication: API key is appended as a query parameter (?key=...) in the
 * URL — there is no Authorization header for the public API tier.
 *
 * Request shape:
 * {
 *   "systemInstruction": { "parts": [{ "text": "<system prompt>" }] },
 *   "contents": [
 *     { "role": "user", "parts": [{ "text": "<user prompt>" }] }
 *   ],
 *   "generationConfig": {
 *     "temperature": 1.0,
 *     "maxOutputTokens": 1024
 *   }
 * }
 *
 * Response shape (relevant fields):
 * {
 *   "candidates": [{
 *     "content": { "parts": [{ "text": "..." }] },
 *     "finishReason": "STOP"
 *   }],
 *   "usageMetadata": {
 *     "promptTokenCount":     12,
 *     "candidatesTokenCount": 45,
 *     "totalTokenCount":      57
 *   },
 *   "modelVersion": "gemini-1.5-pro-002"
 * }
 */
class GeminiProvider extends AbstractAIProvider
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    // =========================================================================
    // AbstractAIProvider — implementation
    // =========================================================================

    /**
     * Gemini authenticates via query parameter, not via header.
     * The API key must NOT appear in getRequestHeaders().
     */
    protected function getEndpointUrl(): string
    {
        // URL structure: /v1beta/models/{model}:generateContent?key={apiKey}
        return sprintf(
            '%s/%s:generateContent?key=%s',
            self::BASE_URL,
            urlencode($this->modelName),
            urlencode($this->apiKey)
        );
    }

    /**
     * No Authorization header — auth is entirely query-param based.
     *
     * @return array<string, string>
     */
    protected function getRequestHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
    }

    /**
     * @param  string               $prompt
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function buildRequestPayload(string $prompt, array $options): array
    {
        $payload = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
        ];

        // Gemini separates the system instruction from the conversation turns.
        if (!empty($options['system']) && is_string($options['system'])) {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $options['system']],
                ],
            ];
        }

        // Collect generation config overrides.
        $generationConfig = [];

        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $generationConfig['temperature'] = (float) $options['temperature'];
        }

        if (isset($options['max_tokens']) && is_int($options['max_tokens'])) {
            // Gemini uses 'maxOutputTokens', not 'max_tokens'.
            $generationConfig['maxOutputTokens'] = $options['max_tokens'];
        }

        if (!empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed> $raw
     * @throws AIProviderException  When candidates array is empty or blocked.
     */
    protected function parseResponse(
        array $raw,
        string $providerSlug,
        string $model,
        float $latencyMs,
    ): AIResponseDTO {
        // Check for a prompt block (safety filter rejection).
        $blockReason = $raw['promptFeedback']['blockReason'] ?? null;
        if ($blockReason !== null) {
            throw new AIProviderException(
                message: sprintf('[gemini] Prompt blocked by safety filter: %s.', $blockReason),
                providerSlug: $providerSlug,
                httpStatusCode: 200,
            );
        }

        $finishReason = $raw['candidates'][0]['finishReason'] ?? 'UNKNOWN';

        // SAFETY or RECITATION means the model refused to generate content.
        if (in_array($finishReason, ['SAFETY', 'RECITATION', 'OTHER'], strict: true)) {
            throw new AIProviderException(
                message: sprintf('[gemini] Content generation stopped: finishReason=%s.', $finishReason),
                providerSlug: $providerSlug,
                httpStatusCode: 200,
            );
        }

        $content = $raw['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!is_string($content)) {
            throw new AIProviderException(
                message: sprintf(
                    '[gemini] Unexpected response shape — text part missing. finishReason=%s.',
                    $finishReason
                ),
                providerSlug: $providerSlug,
                httpStatusCode: 200,
            );
        }

        // modelVersion is the resolved model identifier (e.g. 'gemini-1.5-pro-002').
        $resolvedModel = $raw['modelVersion'] ?? $model;

        return new AIResponseDTO(
            content: $content,
            model: $resolvedModel,
            providerSlug: $providerSlug,
            inputTokens: (int) ($raw['usageMetadata']['promptTokenCount']     ?? 0),
            outputTokens: (int) ($raw['usageMetadata']['candidatesTokenCount'] ?? 0),
            latencyMs: $latencyMs,
            rawResponse: $raw,
        );
    }

    // =========================================================================
    // AIProviderInterface
    // =========================================================================

    public function getProviderSlug(): string
    {
        return 'gemini';
    }

    /**
     * Gemini keys are 39 characters and contain only alphanumeric chars + hyphens.
     * Pattern: /^[A-Za-z0-9_-]{30,}$/
     */
    public function validateApiKey(string $apiKey): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_\-]{30,}$/', trim($apiKey));
    }
}
