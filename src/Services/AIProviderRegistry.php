<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Services;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use Kalakotra\AIGateway\Interfaces\AIProviderInterface;
use Kalakotra\AIGateway\Exceptions\AIProviderException;

/**
 * Resolves a provider slug (e.g. 'openai') to its concrete implementation class.
 *
 * The mapping is defined entirely in YAML and never hard-coded here, keeping
 * the Registry open for extension without modification:
 *
 * ```yaml
 * Kalakotra\AIGateway\Services\AIProviderRegistry:
 *   providers:
 *     openai:    Kalakotra\AIGateway\Providers\OpenAIProvider
 *     gemini:    Kalakotra\AIGateway\Providers\GeminiProvider
 *     anthropic: Kalakotra\AIGateway\Providers\AnthropicProvider
 * ```
 *
 * A third-party module adds its own provider by shipping a single YAML file —
 * zero changes required in this class.
 */
class AIProviderRegistry
{
    use Configurable;
    use Injectable;

    /**
     * YAML-driven map of slug => FQCN.
     * Populated entirely by SilverStripe Config API — do not set here.
     *
     * @config
     * @var array<string, class-string<AIProviderInterface>>
     */
    private static array $providers = [];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the FQCN registered for $slug, or null if unknown.
     *
     * @param  string $slug  e.g. 'openai'
     * @return class-string<AIProviderInterface>|null
     */
    public function getProviderClass(string $slug): ?string
    {
        $providers = $this->config()->get('providers') ?? [];
        return $providers[$slug] ?? null;
    }

    /**
     * Return every registered slug => FQCN pair.
     *
     * @return array<string, class-string<AIProviderInterface>>
     */
    public function getAllProviders(): array
    {
        return $this->config()->get('providers') ?? [];
    }

    /**
     * Assert that $slug is registered; throw if not.
     *
     * @throws AIProviderException
     */
    public function requireProviderClass(string $slug): string
    {
        $class = $this->getProviderClass($slug);

        if ($class === null) {
            throw new AIProviderException(
                message: sprintf(
                    'No provider registered for slug "%s". '
                    . 'Add it to AIProviderRegistry.providers in your YAML config. '
                    . 'Registered slugs: [%s].',
                    $slug,
                    implode(', ', array_keys($this->getAllProviders()))
                ),
                providerSlug: $slug,
                httpStatusCode: 0,
            );
        }

        if (!class_exists($class)) {
            throw new AIProviderException(
                message: sprintf(
                    'Provider class "%s" registered for slug "%s" does not exist. '
                    . 'Check your composer autoload configuration.',
                    $class,
                    $slug,
                ),
                providerSlug: $slug,
                httpStatusCode: 0,
            );
        }

        if (!is_a($class, AIProviderInterface::class, true)) {
            throw new AIProviderException(
                message: sprintf(
                    'Provider class "%s" does not implement AIProviderInterface.',
                    $class,
                ),
                providerSlug: $slug,
                httpStatusCode: 0,
            );
        }

        return $class;
    }
}
