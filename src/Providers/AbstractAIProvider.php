<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Psr\Http\Message\ResponseInterface;
use Kalakotra\AIGateway\Exceptions\AIProviderException;
use Kalakotra\AIGateway\Interfaces\AIProviderInterface;
use Kalakotra\AIGateway\Interfaces\AIResponseDTO;

/**
 * Shared Guzzle HTTP infrastructure for all concrete provider implementations.
 *
 * Subclass responsibilities:
 *  - buildRequestPayload()  Translate prompt + options → provider JSON body.
 *  - parseResponse()        Extract content + token counts from decoded JSON.
 *  - getEndpointUrl()       Return the full API URL (including query params if needed).
 *  - getRequestHeaders()    Return provider-specific headers (auth, version, etc.).
 *
 * Everything else — HTTP execution, latency measurement, exception normalisation,
 * and JSON decoding — is handled here and is NOT overridable by subclasses.
 */
abstract class AbstractAIProvider implements AIProviderInterface
{
    protected readonly Client $client;

    /**
     * @param  string $apiKey      Plain-text API key from AIProviderConfig.
     * @param  string $modelName   Model identifier, e.g. 'gpt-4o'.
     * @param  int    $timeoutSecs Guzzle read timeout propagated from gateway config.
     */
    public function __construct(
        protected readonly string $apiKey,
        protected readonly string $modelName,
        protected readonly int $timeoutSecs = 30,
    ) {
        $this->client = new Client([
            'timeout'         => $this->timeoutSecs,
            'connect_timeout' => 10,
            // Per-request headers are set in getRequestHeaders() so each
            // provider controls them fully (Gemini needs no auth header, etc.)
        ]);
    }

    // =========================================================================
    // AIProviderInterface — final implementation
    // =========================================================================

    /**
     * Orchestrate the full prompt→response cycle.
     *
     * Latency is measured exclusively around the network I/O boundary
     * (executeRequest) so payload-build time is NOT included in the metric.
     */
    final public function sendPrompt(string $prompt, array $options = []): AIResponseDTO
    {
        // Build payload BEFORE starting the clock.
        $payload = $this->buildRequestPayload($prompt, $options);

        // --- Network boundary: only this block is timed ---------------------
        $startMs      = (int) (microtime(true) * 1_000);
        $httpResponse = $this->executeRequest($payload);
        $latencyMs    = round((microtime(true) * 1_000) - $startMs, 2);
        // --------------------------------------------------------------------

        $raw = json_decode((string) $httpResponse->getBody(), true) ?? [];

        return $this->parseResponse(
            raw: $raw,
            providerSlug: $this->getProviderSlug(),
            model: $this->modelName,
            latencyMs: $latencyMs,
        );
    }

    // =========================================================================
    // Abstract contract — each concrete provider implements these four methods
    // =========================================================================

    /**
     * Translate the generic prompt + options into the provider-specific JSON body.
     *
     * Supported $options keys (all optional):
     *   'temperature'  float   Sampling temperature (0.0–2.0).
     *   'max_tokens'   int     Maximum output tokens.
     *   'system'       string  System / persona message.
     *
     * @param  string               $prompt
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    abstract protected function buildRequestPayload(string $prompt, array $options): array;

    /**
     * Extract content and token metadata from the provider's decoded JSON response.
     *
     * @param  array<string, mixed> $raw
     */
    abstract protected function parseResponse(
        array $raw,
        string $providerSlug,
        string $model,
        float $latencyMs,
    ): AIResponseDTO;

    /**
     * Return the full endpoint URL for a chat/completion POST.
     * Providers that authenticate via query param (Gemini) append it here.
     */
    abstract protected function getEndpointUrl(): string;

    /**
     * Return all HTTP request headers for this provider.
     * Must include Content-Type and any auth headers.
     *
     * @return array<string, string>
     */
    abstract protected function getRequestHeaders(): array;

    // =========================================================================
    // AIProviderInterface — default validateApiKey()
    // =========================================================================

    /**
     * Syntactic plausibility check — no live API call.
     * Concrete providers may override to enforce prefix/length rules.
     */
    public function validateApiKey(string $apiKey): bool
    {
        return strlen(trim($apiKey)) > 10;
    }

    // =========================================================================
    // Private infrastructure
    // =========================================================================

    /**
     * Execute the HTTP POST and normalise Guzzle's full exception hierarchy
     * into a single typed AIProviderException.
     *
     * Exception priority order (most specific → most general):
     *   ConnectException              network-level failure (0 HTTP status)
     *   ClientException (4xx)         auth, quota, bad request
     *   ServerException (5xx)         provider outage / model overload
     *   TooManyRedirectsException     misconfigured endpoint URL
     *   RequestException (catch-all)  any other Guzzle transfer error
     *
     * @param  array<string, mixed> $payload
     * @throws AIProviderException
     */
    private function executeRequest(array $payload): ResponseInterface
    {
        try {
            return $this->client->post(
                $this->getEndpointUrl(),
                [
                    'headers' => $this->getRequestHeaders(),
                    'json'    => $payload,
                ]
            );

        } catch (ConnectException $e) {
            // DNS failure, TCP timeout, TLS handshake error — no HTTP status.
            throw new AIProviderException(
                message: sprintf('[%s] Connection failed: %s', $this->getProviderSlug(), $e->getMessage()),
                providerSlug: $this->getProviderSlug(),
                httpStatusCode: 0,
                previous: $e,
            );

        } catch (ClientException $e) {
            // 4xx: 401 invalid key, 403 forbidden, 429 rate limit, 400 bad request.
            $status = $e->getResponse()->getStatusCode();
            $body   = (string) $e->getResponse()->getBody();

            throw new AIProviderException(
                message: sprintf('[%s] Client error %d: %s', $this->getProviderSlug(), $status, $this->extractErrorMessage($body)),
                providerSlug: $this->getProviderSlug(),
                httpStatusCode: $status,
                previous: $e,
            );

        } catch (ServerException $e) {
            // 5xx: provider outage, model overloaded, internal server error.
            $status = $e->getResponse()->getStatusCode();
            $body   = (string) $e->getResponse()->getBody();

            throw new AIProviderException(
                message: sprintf(
                    '[%s] Server error %d: %s',
                    $this->getProviderSlug(),
                    $status,
                    $this->extractErrorMessage($body) ?: 'Provider returned no error detail.'
                ),
                providerSlug: $this->getProviderSlug(),
                httpStatusCode: $status,
                previous: $e,
            );

        } catch (TooManyRedirectsException $e) {
            throw new AIProviderException(
                message: sprintf('[%s] Too many redirects — verify the endpoint URL is correct.', $this->getProviderSlug()),
                providerSlug: $this->getProviderSlug(),
                httpStatusCode: 0,
                previous: $e,
            );

        } catch (RequestException $e) {
            // Catch-all for any remaining Guzzle transfer exception.
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body   = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';

            throw new AIProviderException(
                message: sprintf(
                    '[%s] Request failed (HTTP %d): %s',
                    $this->getProviderSlug(),
                    $status,
                    $this->extractErrorMessage($body) ?: $e->getMessage()
                ),
                providerSlug: $this->getProviderSlug(),
                httpStatusCode: $status,
                previous: $e,
            );
        }
    }

    /**
     * Parse the most human-readable error string from a JSON error body.
     *
     * Handles three common provider shapes:
     *   OpenAI / Anthropic  { "error": { "message": "..." } }
     *   Gemini              { "error": { "status": "...", "message": "..." } }
     *   Fallback            raw body truncated to 256 chars
     */
    private function extractErrorMessage(string $body): string
    {
        if (empty($body)) {
            return '';
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return mb_substr($body, 0, 256);
        }

        // OpenAI / Anthropic shape
        if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            return $decoded['error']['message'];
        }

        // Gemini shape
        if (isset($decoded['error']['status'])) {
            $msg = $decoded['error']['message'] ?? '';
            return trim($decoded['error']['status'] . ': ' . $msg, ': ');
        }

        return mb_substr($body, 0, 256);
    }
}
