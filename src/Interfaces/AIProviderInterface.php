<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Interfaces;

/**
 * Standard contract for all AI provider implementations.
 *
 * Every concrete provider (OpenAI, Gemini, Anthropic, etc.) MUST implement
 * this interface. The Gateway Service works exclusively against this contract,
 * ensuring zero coupling to any specific provider.
 */
interface AIProviderInterface
{
    /**
     * Send a prompt to the AI provider and return a structured response.
     *
     * @param  string               $prompt   The user or system prompt to send.
     * @param  array<string, mixed> $options  Optional overrides per call:
     *                                         - 'temperature'  float   (0.0–2.0)
     *                                         - 'max_tokens'   int
     *                                         - 'system'       string  System message / persona
     *                                         - 'stream'       bool    (reserved for future use)
     *
     * @return AIResponseDTO  Structured response containing the text reply
     *                        plus metadata (tokens used, model, latency, etc.)
     *
     * @throws \Kalakotra\AIGateway\Exceptions\AIProviderException  On API or HTTP failure.
     */
    public function sendPrompt(string $prompt, array $options = []): AIResponseDTO;

    /**
     * Return the canonical provider slug used in AIProviderConfig.ProviderName.
     * Must match the key registered in AIProviderRegistry YAML config.
     *
     * Example return values: 'openai' | 'gemini' | 'anthropic'
     */
    public function getProviderSlug(): string;

    /**
     * Validate that the given API key is syntactically plausible for this provider.
     * Should NOT perform a live API call — use for CMS-side pre-save validation only.
     */
    public function validateApiKey(string $apiKey): bool;
}
