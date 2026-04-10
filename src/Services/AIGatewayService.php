<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Services;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Kalakotra\AIGateway\Exceptions\AIProviderException;
use Kalakotra\AIGateway\Interfaces\AIProviderInterface;
use Kalakotra\AIGateway\Interfaces\AIResponseDTO;
use Kalakotra\AIGateway\Models\AILog;
use Kalakotra\AIGateway\Models\AIProviderConfig;

/**
 * Central orchestrator for all AI calls in the Kalakotra ecosystem.
 *
 * Usage from any SilverStripe service or controller:
 *
 * ```php
 * $gateway = AIGatewayService::create();
 * $response = $gateway->ask('Summarise this invoice in two sentences.');
 * echo $response->content;
 * ```
 *
 * The service:
 *  1. Resolves the active AIProviderConfig from the database.
 *  2. Uses AIProviderRegistry to find the concrete provider class.
 *  3. Instantiates the provider via SilverStripe Injector (DI-friendly).
 *  4. Calls sendPrompt() and records the result in AILog.
 *  5. On failure, records an error AILog entry and re-throws a typed exception.
 */
class AIGatewayService
{
    use Configurable;
    use Injectable;

    /**
     * Write an AILog entry for every call (success and failure).
     * Can be disabled in test environments via YAML:
     *   Kalakotra\AIGateway\Services\AIGatewayService:
     *     enable_logging: false
     *
     * @config
     */
    private static bool $enable_logging = true;

    /**
     * Guzzle HTTP timeout in seconds, propagated to provider constructors.
     *
     * @config
     */
    private static int $http_timeout = 30;

    // -------------------------------------------------------------------------
    // Constructor — dependencies injected; all have sensible defaults
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly AIProviderRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Primary public API
    // -------------------------------------------------------------------------

    /**
     * Send $prompt to the currently active AI provider and return a typed DTO.
     *
     * @param  string               $prompt   The prompt text.
     * @param  array<string, mixed> $options  Per-call overrides forwarded to the provider:
     *                                         - 'temperature'    float
     *                                         - 'max_tokens'     int
     *                                         - 'system'         string
     *                                         - 'caller_class'   string  (for log attribution)
     *                                         - 'caller_context' string  (for log attribution)
     *
     * @throws AIProviderException  When no active provider is configured, the
     *                              provider class cannot be resolved, or the
     *                              upstream API call fails.
     */
    public function ask(string $prompt, array $options = []): AIResponseDTO
    {
        // -- 1. Resolve active provider configuration from DB ---------------------
        $providerConfig = $this->resolveActiveConfig();

        // -- 2. Resolve provider class via Registry --------------------------------
        $providerClass = $this->registry->requireProviderClass($providerConfig->ProviderName);

        // -- 3. Instantiate provider through Injector (allows test substitution) --
        /** @var AIProviderInterface $provider */
        $provider = Injector::inst()->createWithArgs($providerClass, [
            $providerConfig->APIKey,
            $providerConfig->ModelName,
            $this->config()->get('http_timeout'),
        ]);

        // -- 4. Execute the prompt ------------------------------------------------
        $callerClass   = (string) ($options['caller_class']   ?? '');
        $callerContext = (string) ($options['caller_context'] ?? '');

        // Strip internal meta-keys before forwarding options to the provider
        $providerOptions = array_diff_key($options, array_flip(['caller_class', 'caller_context']));

        $startTime = microtime(true);

        try {
            $response = $provider->sendPrompt($prompt, $providerOptions);
        } catch (AIProviderException $e) {
            // Log the failure, then re-throw so the caller can handle it
            $this->logError(
                exception: $e,
                prompt: $prompt,
                providerConfig: $providerConfig,
                callerClass: $callerClass,
                callerContext: $callerContext,
            );

            $this->logger->error('[AIGateway] Provider call failed', [
                'provider'    => $providerConfig->ProviderName,
                'model'       => $providerConfig->ModelName,
                'http_status' => $e->getHttpStatusCode(),
                'message'     => $e->getMessage(),
            ]);

            throw $e;

        } catch (\Throwable $e) {
            // Unexpected exception: wrap in AIProviderException for consistent handling
            $wrapped = new AIProviderException(
                message: 'Unexpected error during provider call: ' . $e->getMessage(),
                providerSlug: $providerConfig->ProviderName,
                httpStatusCode: 0,
                previous: $e,
            );

            $this->logError(
                exception: $wrapped,
                prompt: $prompt,
                providerConfig: $providerConfig,
                callerClass: $callerClass,
                callerContext: $callerContext,
            );

            $this->logger->critical('[AIGateway] Unexpected exception', [
                'provider'  => $providerConfig->ProviderName,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            throw $wrapped;
        }

        // -- 5. Persist audit log for successful call -----------------------------
        $this->logSuccess(
            response: $response,
            prompt: $prompt,
            providerConfig: $providerConfig,
            callerClass: $callerClass,
            callerContext: $callerContext,
        );

        $this->logger->info('[AIGateway] Call completed', [
            'provider'      => $response->providerSlug,
            'model'         => $response->model,
            'total_tokens'  => $response->totalTokens(),
            'latency_ms'    => $response->latencyMs,
        ]);

        return $response;
    }

    /**
     * Convenience wrapper: ask() and return only the response text.
     * Useful for simple string-in / string-out integrations.
     *
     * @throws AIProviderException
     */
    public function askText(string $prompt, array $options = []): string
    {
        return $this->ask($prompt, $options)->content;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Return the one AIProviderConfig that has IsActive = true.
     *
     * @throws AIProviderException  When no active provider is configured.
     */
    private function resolveActiveConfig(): AIProviderConfig
    {
        /** @var AIProviderConfig|null $config */
        $config = AIProviderConfig::get()
            ->filter('IsActive', true)
            ->first();

        if ($config === null) {
            throw new AIProviderException(
                message: 'No active AI provider is configured. '
                    . 'Go to Admin → AI Gateway and mark one provider as Active.',
                providerSlug: 'none',
                httpStatusCode: 0,
            );
        }

        return $config;
    }

    /**
     * Write a success AILog entry if logging is enabled.
     */
    private function logSuccess(
        AIResponseDTO $response,
        string $prompt,
        AIProviderConfig $providerConfig,
        string $callerClass,
        string $callerContext,
    ): void {
        if (!$this->config()->get('enable_logging')) {
            return;
        }

        try {
            AILog::createFromResponse(
                response: $response,
                prompt: $prompt,
                providerConfig: $providerConfig,
                callerClass: $callerClass,
                callerContext: $callerContext,
            );
        } catch (\Throwable $logException) {
            // Log persistence must never break the primary response flow
            $this->logger->error('[AIGateway] Failed to persist success log', [
                'message' => $logException->getMessage(),
            ]);
        }
    }

    /**
     * Write an error AILog entry if logging is enabled.
     */
    private function logError(
        AIProviderException $exception,
        string $prompt,
        AIProviderConfig $providerConfig,
        string $callerClass,
        string $callerContext,
    ): void {
        if (!$this->config()->get('enable_logging')) {
            return;
        }

        try {
            AILog::createFromException(
                exception: $exception,
                prompt: $prompt,
                providerSlug: $providerConfig->ProviderName,
                modelName: $providerConfig->ModelName,
                providerConfig: $providerConfig,
                callerClass: $callerClass,
                callerContext: $callerContext,
            );
        } catch (\Throwable $logException) {
            // Same as above — log failure must not suppress the original exception
            $this->logger->error('[AIGateway] Failed to persist error log', [
                'message' => $logException->getMessage(),
            ]);
        }
    }
}
