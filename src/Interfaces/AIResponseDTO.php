<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Interfaces;

/**
 * Immutable Value Object returned by every AIProviderInterface::sendPrompt() call.
 *
 * Using a typed DTO instead of a plain string allows the Gateway Service and
 * calling modules to access token usage, latency, and the raw provider response
 * without any additional parsing. All properties are readonly to enforce
 * immutability after construction.
 */
final readonly class AIResponseDTO
{
    /**
     * @param  string               $content       The AI-generated text reply.
     * @param  string               $model         Exact model identifier used (e.g. 'gpt-4o', 'gemini-1.5-pro').
     * @param  string               $providerSlug  Provider slug that generated this response (e.g. 'openai').
     * @param  int                  $inputTokens   Number of prompt/input tokens consumed.
     * @param  int                  $outputTokens  Number of completion/output tokens generated.
     * @param  float                $latencyMs     Wall-clock time for the HTTP round-trip in milliseconds.
     * @param  array<string, mixed> $rawResponse   The full decoded JSON response body from the provider API.
     *                                             Stored for audit/debugging; never exposed to end users directly.
     */
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly string $providerSlug,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly float $latencyMs = 0.0,
        public readonly array $rawResponse = [],
    ) {}

    /**
     * Convenience accessor: total tokens consumed by this call.
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * Serialise to array for logging / persistence in AILog.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content'       => $this->content,
            'model'         => $this->model,
            'provider_slug' => $this->providerSlug,
            'input_tokens'  => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens'  => $this->totalTokens(),
            'latency_ms'    => $this->latencyMs,
        ];
    }
}
