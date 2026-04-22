# AI Gateway for SilverStripe 6

A standardized AI provider gateway for SilverStripe 6.1+ that abstracts multiple AI providers (OpenAI, Google Gemini, Anthropic Claude, Mittwald Open-LLM) behind a unified interface. Supports separate active providers per generation category (text / image).

## Features

- **Multi-provider support**: OpenAI GPT, Google Gemini, Anthropic Claude, Mittwald Open-LLM
- **Category-aware routing**: One active provider per category — a text provider and an image provider can be active simultaneously
- **Unified API**: Single `ask()` call regardless of provider
- **Audit logging**: Every call (success and failure) is persisted to `AILog` with token accounting and latency
- **CMS management**: Create, activate, and test providers from `/admin/ai-gateway/`
- **Connection testing**: GridField button — live API ping for text providers; API key format check for image providers
- **Typed exceptions**: `AIProviderException` carries HTTP status code for upstream error classification
- **Extensible**: Add new providers by implementing `AIProviderInterface`

## Installation

```bash
composer require kalakotra/silverstripe-aigateway
vendor/bin/sake dev/build flush=all
```

## CMS Setup

1. Go to **CMS → AI Gateway → Providers**
2. Click **Add Provider** and fill in:
   - **Label** — human-readable name, e.g. `OpenAI GPT-4o Production`
   - **Provider** — select from dropdown
   - **Model Name** — exact model ID (see [Supported Providers](#supported-providers))
   - **Category** — `Text generation` or `Image generation`
   - **API Key** — your provider secret key
3. Click **⚡ Test** to verify the connection
4. Check **Set as Active Provider** and save

One provider per category may be active at a time. Activating a provider automatically deactivates any other active provider in the same category. Providers in different categories are independent.

## Usage

### Text generation

```php
use Kalakotra\AIGateway\Services\AIGatewayService;
use Kalakotra\AIGateway\Exceptions\AIProviderException;
use SilverStripe\Core\Injector\Injector;

/** @var AIGatewayService $gateway */
$gateway = Injector::inst()->get('AIGateway');

try {
    // Full response DTO
    $response = $gateway->ask('Summarise this invoice in two sentences.', [
        'caller_class'   => self::class,
        'caller_context' => 'invoice-summary',
        'temperature'    => 0.4,
        'max_tokens'     => 256,
    ]);

    echo $response->content;        // Generated text
    echo $response->model;          // Resolved model (e.g. 'gpt-4o-2024-08-06')
    echo $response->totalTokens();  // input + output tokens
    echo $response->latencyMs;      // Request duration in ms

    // String-only shortcut
    $text = $gateway->askText('Write a meta description for: ' . $page->Title);

} catch (AIProviderException $e) {
    // $e->getHttpStatusCode() — 429 = rate limit, 401 = bad key, 0 = no provider configured
    error_log('[AI] ' . $e->getMessage());
}
```

### Image generation

```php
$response = $gateway->askImage('A photorealistic red apple on a white background', [
    'caller_class'   => self::class,
    'caller_context' => 'product-image',
]);
```

`askImage()` is a shortcut for `ask($prompt, ['category' => 'image', ...])`. The active image-category provider handles the call.

### Passing category explicitly

```php
$response = $gateway->ask($prompt, ['category' => 'text']);   // default
$response = $gateway->ask($prompt, ['category' => 'image']);
```

### Available options

| Option           | Type    | Default  | Description                              |
|------------------|---------|----------|------------------------------------------|
| `category`       | string  | `'text'` | Which active provider to use             |
| `temperature`    | float   | provider | Sampling temperature (0.0 – 2.0)         |
| `max_tokens`     | int     | provider | Maximum output tokens                    |
| `system`         | string  | —        | System prompt / persona                  |
| `caller_class`   | string  | `''`     | Class that triggered the call (for logs) |
| `caller_context` | string  | `''`     | Context label (for logs)                 |

### Dependency injection

```php
class MySEOService
{
    public function __construct(
        private readonly AIGatewayService $gateway,
    ) {}

    public function generateMetaDescription(string $content): string
    {
        return $this->gateway->askText(
            'Write a 155-character meta description for: ' . $content,
            ['caller_class' => self::class, 'caller_context' => 'meta-description'],
        );
    }
}
```

Wire it in YAML:

```yaml
SilverStripe\Core\Injector\Injector:
  MyApp\Services\MySEOService:
    constructor:
      gateway: '%$AIGateway'
```

## YAML Configuration

The module ships with sensible defaults in `_config/aigateway.yml`. Override per environment:

```yaml
# app/_config/aigateway.yml
---
Name: myproject-aigateway
After: aigateway
---

Kalakotra\AIGateway\Services\AIGatewayService:
  enable_logging: true   # set false in test suites
  http_timeout: 30       # Guzzle timeout in seconds
```

### Registering additional providers

```yaml
Kalakotra\AIGateway\Services\AIProviderRegistry:
  providers:
    mistral: MyApp\AI\Providers\MistralProvider
```

The slug (`mistral`) must match the `ProviderName` stored in `AIProviderConfig`.

## Architecture

### Request flow

```
$gateway->ask($prompt, $options)
    │
    ├─ resolveActiveConfig(category)   ← AIProviderConfig::filter(IsActive, Category)
    │
    ├─ AIProviderRegistry::requireProviderClass(providerName)
    │
    ├─ Injector::createWithArgs(providerClass, [apiKey, modelName, timeout])
    │
    ├─ AIProviderInterface::sendPrompt($prompt, $providerOptions)
    │       ├─ buildRequestPayload()   → provider-specific JSON
    │       ├─ Guzzle POST             → upstream API
    │       └─ parseResponse()        → AIResponseDTO
    │
    ├─ AILog::createFromResponse()     (if enable_logging)
    │
    └─ return AIResponseDTO
         ↕ on exception
    AILog::createFromException() + re-throw AIProviderException
```

### Key classes

| Class | Responsibility |
|---|---|
| `AIGatewayService` | Orchestrator — resolves config, instantiates provider, logs |
| `AIProviderRegistry` | YAML-driven slug → class map |
| `AIProviderConfig` | DB record — credentials, model, category, active flag |
| `AILog` | Immutable audit entry per call |
| `AIResponseDTO` | Normalized response (content, model, tokens, latency, raw) |
| `AIProviderException` | Typed exception with HTTP status code |

### AIProviderConfig fields

| Field | Type | Notes |
|---|---|---|
| `Label` | Varchar | Human-readable name |
| `ProviderName` | Varchar | Must match registry slug |
| `ModelName` | Varchar | Exact model ID passed to the API |
| `Category` | Enum | `text` (default) or `image` |
| `IsActive` | Boolean | One active per category |
| `APIKey` | Text | Masked in CMS; encrypt at rest in production |
| `Notes` | Text | Internal CMS notes |

## Extending with a custom provider

Extend `AbstractAIProvider` (handles Guzzle, timing, error mapping) and implement the three abstract methods:

```php
namespace MyApp\AI\Providers;

use Kalakotra\AIGateway\Exceptions\AIProviderException;
use Kalakotra\AIGateway\Interfaces\AIResponseDTO;
use Kalakotra\AIGateway\Providers\AbstractAIProvider;

class MistralProvider extends AbstractAIProvider
{
    protected function getEndpointUrl(): string
    {
        return 'https://api.mistral.ai/v1/chat/completions';
    }

    protected function getRequestHeaders(): array
    {
        return [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];
    }

    protected function buildRequestPayload(string $prompt, array $options): array
    {
        return [
            'model'    => $this->modelName,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
    }

    protected function parseResponse(array $raw, string $providerSlug, string $model, float $latencyMs): AIResponseDTO
    {
        $content = $raw['choices'][0]['message']['content'] ?? null;

        if (!is_string($content)) {
            throw new AIProviderException(
                message: "[$providerSlug] Unexpected response shape.",
                providerSlug: $providerSlug,
                httpStatusCode: 200,
            );
        }

        return new AIResponseDTO(
            content: $content,
            model: $raw['model'] ?? $model,
            providerSlug: $providerSlug,
            inputTokens: (int) ($raw['usage']['prompt_tokens']     ?? 0),
            outputTokens: (int) ($raw['usage']['completion_tokens'] ?? 0),
            latencyMs: $latencyMs,
            rawResponse: $raw,
        );
    }

    public function getProviderSlug(): string { return 'mistral'; }

    public function validateApiKey(string $apiKey): bool
    {
        return strlen(trim($apiKey)) >= 32;
    }
}
```

Register the slug in YAML (see above), then create an `AIProviderConfig` record in the CMS.

## Supported providers

### OpenAI
- **Models**: `gpt-4o`, `gpt-4o-mini`, `o3`, `o4-mini`
- **Key format**: starts with `sk-`, min 40 chars
- **Notes**: Reasoning models (`o*`) use `max_completion_tokens` automatically

### Google Gemini
- **Models**: `gemini-1.5-pro`, `gemini-2.0-flash`, `gemini-3-pro-image` (image category)
- **Authentication**: API key as query parameter (no `Authorization` header)
- **Notes**: Image-category providers are validated by key format only — no live generation call is made during the connection test

### Anthropic Claude
- **Models**: `claude-opus-4-7`, `claude-sonnet-4-6`, `claude-haiku-4-5-20251001`
- **Key format**: starts with `sk-ant-`

### Mittwald Open-LLM
- **Endpoint**: `https://llm.aihosting.mittwald.de/v1/chat/completions`
- **Protocol**: OpenAI-compatible (reuses `OpenAIProvider` request/response handling)
- **Notes**: Some open-source models return `content: null` on token truncation; the provider handles this gracefully by returning an empty string

## Connection test behaviour

| Category | Test action |
|---|---|
| `text` | Sends `"Reply with exactly one word: pong"` — live API call, verifies key + model + quota |
| `image` | Validates API key format only — no generation call, no quota consumed |

## Error handling

All failures throw `AIProviderException`:

```php
use Kalakotra\AIGateway\Exceptions\AIProviderException;

try {
    $response = $gateway->ask($prompt);
} catch (AIProviderException $e) {
    $code = $e->getHttpStatusCode();
    // 0   = no active provider configured for the requested category
    // 401 = invalid API key
    // 429 = rate limit / quota exceeded
    // 5xx = upstream server error
}
```

## Security

- API keys are rendered via `PasswordField` in the CMS (not visible on screen)
- API keys are **not** exported via the GridField export button (export is disabled)
- For production: encrypt keys at rest using SilverStripe's symmetric encryption helpers or a secrets manager
- All prompts and responses are logged server-side only; never expose `AILog` data to end users
- Validate and sanitize user input before including it in prompts

## Requirements

- PHP 8.2+
- SilverStripe 6.1+
- Guzzle HTTP Client 7.8+

## License

Proprietary.

## Changelog

### v1.1.0
- Added `Category` field (`text` / `image`) to `AIProviderConfig`
- One active provider allowed per category (text and image can be active simultaneously)
- Added `askImage()` convenience method
- Connection test is now category-aware: image providers skip live call, validate key format only
- `OpenAIProvider`: graceful handling of `finish_reason: length` with null content (Mittwald compatibility)

### v1.0.0
- Multi-provider gateway (OpenAI, Gemini, Anthropic, Mittwald Open-LLM)
- `ask()` / `askText()` unified API
- `AILog` audit logging with token accounting
- CMS provider management and connection testing
