<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Providers;

use Kalakotra\AIGateway\Exceptions\AIProviderException;
use Kalakotra\AIGateway\Interfaces\AIResponseDTO;

/**
 * Anthropic provider — targets the Messages API (Claude models).
 *
 * API reference: https://docs.anthropic.com/en/api/messages
 *
 * Required headers:
 *   x-api-key:          <key>
 *   anthropic-version:  2023-06-01
 *   Content-Type:       application/json
 *
 * Request shape:
 * {
 *   "model":      "claude-sonnet-4-6",
 *   "max_tokens": 1024,
 *   "system":     "<system prompt>",
 *   "messages": [
 *     { "role": "user", "content": "<user prompt>" }
 *   ],
 *   "temperature": 1.0
 * }
 *
 * Response shape (relevant fields):
 * {
 *   "content": [{ "type": "text", "text": "..." }],
 *   "model":   "claude-sonnet-4-6-20250514",
 *   "usage":   { "input_tokens": 12, "output_tokens": 45 },
 *   "stop_reason": "end_turn"
 * }
 */
class AnthropicProvider extends AbstractAIProvider
{
    private const ENDPOINT         = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION      = '2023-06-01';

    /**
     * Anthropic requires max_tokens in every request — there is no server-side
     * default. We use a sensible fallback when the caller does not specify one.
     */
    private const DEFAULT_MAX_TOKENS = 1024;

    // =========================================================================
    // AbstractAIProvider — implementation
    // =========================================================================

    protected function getEndpointUrl(): string
    {
        return self::ENDPOINT;
    }

    /**
     * Anthropic uses x-api-key rather than the Bearer scheme.
     * anthropic-version is mandatory per their API contract.
     *
     * @return array<string, string>
     */
    protected function getRequestHeaders(): array
    {
        return [
            'Content-Type'      => 'application/json',
            'Accept'            => 'application/json',
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
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
            'model'      => $this->modelName,
            'max_tokens' => isset($options['max_tokens']) && is_int($options['max_tokens'])
                ? $options['max_tokens']
                : self::DEFAULT_MAX_TOKENS,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
        ];

        // The 'system' prompt is a top-level field in Anthropic's API,
        // not part of the messages array.
        if (!empty($options['system']) && is_string($options['system'])) {
            $payload['system'] = $options['system'];
        }

        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed> $raw
     * @throws AIProviderException  On unexpected stop_reason or missing content.
     */
    protected function parseResponse(
        array $raw,
        string $providerSlug,
        string $model,
        float $latencyMs,
    ): AIResponseDTO {
        $stopReason = $raw['stop_reason'] ?? 'unknown';

        // 'max_tokens' means the response was truncated — still usable but
        // log it as a warning via the rawResponse for audit purposes.
        // 'end_turn' is the normal successful termination.
        // Any other reason (tool_use, stop_sequence) is also accepted here;
        // callers may inspect rawResponse for further detail.
        if ($stopReason === 'error') {
            throw new AIProviderException(
                message: sprintf(
                    '[anthropic] API returned stop_reason=error. '
                    . 'Check the rawResponse field in AILog for details.'
                ),
                providerSlug: $providerSlug,
                httpStatusCode: 200,
            );
        }

        // Anthropic returns content as an array of typed blocks.
        // We concatenate all 'text' blocks to support future multi-block responses.
        $contentBlocks = $raw['content'] ?? [];
        $textParts     = [];

        foreach ($contentBlocks as $block) {
            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $textParts[] = $block['text'];
            }
        }

        if (empty($textParts)) {
            throw new AIProviderException(
                message: sprintf(
                    '[anthropic] Unexpected response shape — no text content block found. '
                    . 'stop_reason=%s.',
                    $stopReason
                ),
                providerSlug: $providerSlug,
                httpStatusCode: 200,
            );
        }

        // Anthropic returns the resolved model version (e.g. 'claude-sonnet-4-6-20250514').
        $resolvedModel = $raw['model'] ?? $model;

        return new AIResponseDTO(
            content: implode('', $textParts),
            model: $resolvedModel,
            providerSlug: $providerSlug,
            inputTokens: (int) ($raw['usage']['input_tokens']  ?? 0),
            outputTokens: (int) ($raw['usage']['output_tokens'] ?? 0),
            latencyMs: $latencyMs,
            rawResponse: $raw,
        );
    }

    // =========================================================================
    // AIProviderInterface
    // =========================================================================

    public function getProviderSlug(): string
    {
        return 'anthropic';
    }

    /**
     * Anthropic keys follow the pattern: sk-ant-{type}-{payload}
     * Minimum realistic length is ~60 characters.
     */
    public function validateApiKey(string $apiKey): bool
    {
        return str_starts_with(trim($apiKey), 'sk-ant-') && strlen(trim($apiKey)) >= 60;
    }
}
