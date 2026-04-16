# AI Gateway for SilverStripe 6

A standardized AI provider gateway layer for SilverStripe 6.1+ that abstracts multiple AI providers (OpenAI, Google Gemini, Anthropic Claude, and Mittwald Open-LLM) behind a unified interface. Built on the Strategy pattern for easy provider switching and extensibility.

## Features

- **Multi-provider support**: Seamlessly switch between OpenAI GPT, Google Gemini, Anthropic Claude, and Mittwald Open-LLM
- **Unified API**: Single interface for all AI providers with consistent response handling
- **Audit logging**: Automatic tracking of all API calls with token accounting and latency metrics
- **Provider management**: CMS interface to manage, test, and activate AI provider configurations
- **Error handling**: Typed exceptions for AI-specific errors vs. network/system errors
- **Connection testing**: GridField action to verify provider credentials in real-time
- **Token accounting**: Per-call tracking of input/output tokens for cost analysis and rate limiting
- **Extensible**: Easy to add new providers by implementing `AIProviderInterface`

## Installation

Install via Composer:

```bash
composer require kalakotra/silverstripe-aigateway
```

Run database migrations:

```bash
vendor/bin/sake dev/build flush=all
```

## Configuration

### Step 1: Set Active Provider

In your SilverStripe CMS, navigate to **AI Gateway** admin section and create/activate one of:
- **OpenAI** (GPT-4o, GPT-4 Turbo, etc.)
- **Google Gemini** (Gemini 1.5 Pro, etc.)
- **Anthropic Claude** (Claude 3.5 Sonnet, etc.)
- **Mittwald Open-LLM** (self-hosted OpenAI-compatible models)

### Step 2: Configure in YAML (Optional)

Register additional providers or override defaults in your project config:

```yaml
# app/_config/aigateway.yml
---
Name: myproject-aigateway
After: aigateway

Kalakotra\AIGateway\Services\AIProviderRegistry:
  providers:
    openai:
      label: 'OpenAI GPT-4o'
      class: 'Kalakotra\AIGateway\Providers\OpenAIProvider'
      endpoint: 'https://api.openai.com/v1/chat/completions'
    anthropic:
      label: 'Anthropic Claude'
      class: 'Kalakotra\AIGateway\Providers\AnthropicProvider'
      endpoint: 'https://api.anthropic.com/v1/messages'
        mittwald-open-llm:
            label: 'Mittwald Open-LLM'
            class: 'Kalakotra\AIGateway\Providers\MittwaldOpenLLMProvider'
            endpoint: 'https://llm.aihosting.mittwald.de/v1/chat/completions'
    gemini:
      label: 'Google Gemini'
      class: 'Kalakotra\AIGateway\Providers\GeminiProvider'
      endpoint: 'https://generativelanguage.googleapis.com/v1beta/models'

# Optional: enable audit logging for all calls
Kalakotra\AIGateway\Services\AIGatewayService:
  enable_logging: true
  log_retention_days: 90  # Auto-prune logs older than 90 days
```

### Step 3: Add API Keys

1. Go to **CMS → AI Gateway → Providers**
2. Create a new provider configuration
3. Fill in:
   - **Label**: Human-readable name (e.g., "OpenAI GPT-4o Production")
   - **Provider**: Select from dropdown
   - **Model Name**: Exact model ID (e.g., `gpt-4o`, `claude-3-5-sonnet-20241022`)
   - **API Key**: Your provider's secret key
4. Click the **Test Connection** action to verify immediately
5. Check **Set as Active Provider** and save

> **Security Note**: In production, use SilverStripe's symmetric encryption or a secrets manager to protect API keys. Keys are stored as plain text in the default database setup for development convenience.

## Usage

### Basic Prompt

```php
use Kalakotra\AIGateway\Services\AIGatewayService;
use SilverStripe\Core\Injector\Injector;

$gateway = Injector::inst()->get(AIGatewayService::class);

try {
    $response = $gateway->sendPrompt(
        prompt: "Summarize this: {$pageContent}",
        callerClass: MyPageAnalyzer::class,
        callerContext: 'page-summary'
    );

    echo $response->content;  // The AI response text
    echo $response->model;    // Model used (e.g., "gpt-4o")
    echo $response->totalTokens();  // Token count for billing
    
} catch (\Kalakotra\AIGateway\Exceptions\AIProviderException $e) {
    // Handle AI provider errors (invalid key, rate limit, etc.)
    error_log("AI error: " . $e->getMessage());
    
} catch (\Exception $e) {
    // Handle network/system errors
    error_log("System error: " . $e->getMessage());
}
```

### Inject via Injector

```php
use Kalakotra\AIGateway\Services\AIGatewayService;
use SilverStripe\Core\Extension;

class MyPageExtension extends Extension
{
    private AIGatewayService $gateway;

    public function __construct(AIGatewayService $gateway)
    {
        parent::__construct();
        $this->gateway = $gateway;
    }

    public function analyzeForSEO(): string
    {
        $response = $this->gateway->sendPrompt(
            prompt: "Generate SEO recommendations for: " . $this->owner->Content,
            callerClass: self::class,
            callerContext: 'seo-analysis'
        );

        return $response->content;
    }
}
```

### Get Audit Log

View all API calls in **CMS → AI Gateway → Call Logs**:

- **Provider & Model**: Which service handled the call
- **Prompt (preview)**: First 512 characters of the prompt
- **Response Text**: Full API response
- **Token accounting**: Input, output, total tokens consumed
- **Latency**: Request duration in milliseconds
- **Status**: ✓ OK or ✗ Error
- **Caller info**: Which module/context triggered the call

Export logs programmatically:

```php
use Kalakotra\AIGateway\Models\AILog;

$logs = AILog::get()
    ->filter('ProviderSlug', 'openai')
    ->filter('IsError', false)
    ->sort('Created DESC')
    ->limit(100);

foreach ($logs as $log) {
    echo "{$log->Created}: {$log->ProviderSlug} - {$log->ModelName} - {$log->TotalTokens} tokens\n";
}
```

## Architecture

### Components

#### AIGatewayService
Central orchestrator that:
1. Retrieves the active provider configuration
2. Instantiates the corresponding provider (Strategy pattern)
3. Sends the prompt and receives a normalized response
4. Writes audit log entries
5. Handles and re-throws typed exceptions

#### Providers
Concrete implementations for each AI service:

- **`OpenAIProvider`**: Communicates with OpenAI API (GPT-4, GPT-4o, etc.)
- **`GeminiProvider`**: Communicates with Google Vertex AI / Generative Language API
- **`AnthropicProvider`**: Communicates with Anthropic's Messages API (Claude)
- **`MittwaldOpenLLMProvider`**: Communicates with Mittwald's self-hosted OpenAI-compatible endpoint

Each provider:
- Transforms the generic prompt into provider-specific request format
- Parses provider-specific response into normalized `AIResponseDTO`
- Handles provider-specific error codes and token accounting

#### AIResponseDTO
Unified response object regardless of provider:

```php
class AIResponseDTO
{
    public string $providerSlug;        // 'openai', 'gemini', 'anthropic'
    public string $model;               // Model ID used
    public string $content;             // Generated text
    public int $inputTokens;            // Prompt tokens
    public int $outputTokens;           // Completion tokens
    public float $latencyMs;            // Request duration
    public array $rawResponse;          // Full API response (for debugging)
}
```

#### AILog
Immutable audit log entry created for every call (success or failure):

- Stores provider, model, prompt, response, tokens, latency
- Records caller context (which module/feature requested the call)
- Tracks errors: exception class and message
- Queryable for cost analysis, debugging, and compliance

#### AIProviderConfig
CMS-managed configuration object:

- One record per provider setup (e.g., "OpenAI GPT-4o Production", "Gemini Dev")
- Only one can be active at any time (enforced in `onBeforeWrite`)
- Stores API key (plain text in dev; use secrets manager in production)
- Supports testing connection directly from CMS

### Request Flow

```
Client Code
    ↓
AIGatewayService::sendPrompt()
    ↓
Fetch Active Provider Config
    ↓
Instantiate Concrete Provider (Strategy)
    ↓
Provider::sendPrompt()
    ├─ Format request for provider API
    ├─ Call provider endpoint (via Guzzle)
    ├─ Parse response → AIResponseDTO
    └─ Return DTO
    ↓
Log Call to AILog (success)
    ↓
Return AIResponseDTO to Client
    ↓
On Exception:
    ├─ Log error entry to AILog
    └─ Throw typed exception (AIProviderException, etc.)
```

## Error Handling

### Exception Hierarchy

```
AIProviderException
└─ InvalidProviderException    // Unknown provider slug
└─ NoActiveProviderException   // No active configuration set
└─ ProviderAuthException       // Invalid API key / auth failed
└─ ProviderRateLimitException  // Rate limit hit
└─ ProviderServerException     // 5xx error from provider
```

### Example Error Handling

```php
use Kalakotra\AIGateway\Services\AIGatewayService;
use Kalakotra\AIGateway\Exceptions\{
    AIProviderException,
    ProviderRateLimitException,
    NoActiveProviderException
};

try {
    $response = $gateway->sendPrompt($prompt, MyClass::class, 'context');
} catch (NoActiveProviderException $e) {
    // Prompt user to configure a provider in CMS
    return $this->httpError(503, 'AI service not configured.');
} catch (ProviderRateLimitException $e) {
    // Implement backoff and retry
    return $this->httpError(429, 'AI service temporarily busy. Try again in a moment.');
} catch (AIProviderException $e) {
    // Log and show generic message
    $this->logger->error('AI Error', ['message' => $e->getMessage()]);
    return $this->httpError(502, 'AI service unavailable.');
}
```

## Extending with Custom Providers

Implement `AIProviderInterface` to add a new provider:

```php
namespace MyApp\AI\Providers;

use Guzzle\Http\Client;
use Kalakotra\AIGateway\Interfaces\{AIProviderInterface, AIResponseDTO};
use Kalakotra\AIGateway\Exceptions\AIProviderException;

class MyCustomProvider implements AIProviderInterface
{
    private Client $httpClient;
    private string $apiKey;

    public function __construct(Client $httpClient, string $apiKey)
    {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
    }

    public function sendPrompt(string $prompt, string $model): AIResponseDTO
    {
        try {
            $startTime = microtime(true);
            $response = $this->httpClient->post('https://api.myprovider.com/v1/generate', [
                'headers' => ['Authorization' => "Bearer {$this->apiKey}"],
                'json' => ['prompt' => $prompt, 'model' => $model],
            ]);
            $latencyMs = (microtime(true) - $startTime) * 1000;

            $data = json_decode($response->getBody(), true);

            return new AIResponseDTO(
                providerSlug: 'myprovider',
                model: $model,
                content: $data['generated_text'],
                inputTokens: $data['input_tokens'] ?? 0,
                outputTokens: $data['output_tokens'] ?? 0,
                latencyMs: $latencyMs,
                rawResponse: $data,
            );

        } catch (\Throwable $e) {
            throw new AIProviderException(
                "MyCustomProvider error: " . $e->getMessage(),
                previous: $e
            );
        }
    }
}
```

Register in YAML:

```yaml
Kalakotra\AIGateway\Services\AIProviderRegistry:
  providers:
    myprovider:
      label: 'My Custom Provider'
      class: 'MyApp\AI\Providers\MyCustomProvider'
```

## Supported Providers

### OpenAI
- Models: `gpt-4o`, `gpt-4-turbo`, `gpt-3.5-turbo`
- Endpoint: `https://api.openai.com/v1/chat/completions`
- [Get API Key](https://platform.openai.com/api-keys)

### Google Gemini
- Models: `gemini-1.5-pro`, `gemini-pro`, `gemini-pro-vision`
- Endpoint: `https://generativelanguage.googleapis.com/v1beta/models`
- [Get API Key](https://makersuite.google.com/app/apikey)

### Anthropic Claude
- Models: `claude-3-5-sonnet-20241022`, `claude-opus-4-6`, `claude-sonnet-3-5`
- Endpoint: `https://api.anthropic.com/v1/messages`
- [Get API Key](https://console.anthropic.com)

## Debugging

### Debug Mode

Enable verbose logging:

```php
use SilverStripe\Core\Injector\Injector;
use Psr\Log\LoggerInterface;

$logger = Injector::inst()->get(LoggerInterface::class);
$logger->debug('Calling AI', [
    'provider' => 'openai',
    'model' => 'gpt-4o',
    'tokens' => 150,
]);
```

### View Logs in CMS

Navigate to **CMS → AI Gateway → Call Logs** to see:
- All prompt/response history
- Token usage per provider
- Error history with exception messages
- Latency metrics for optimization

### Test Connection

From **AI Gateway → Providers**, click **Test Connection** to immediately verify:
- API key validity
- Provider endpoint reachability
- Model availability
- Token accounting works

## Performance Considerations

- **Token limits**: Watch aggregate token usage in AILog to stay within provider rate limits
- **Caching**: Cache AI responses by prompt hash to reduce redundant calls
- **Async**: For long-running analyses, consider deferring prompt calls to queued jobs
- **Retry logic**: Providers may fail transiently; implement exponential backoff for critical paths

## Security

- **API Keys**: Store keys in a secrets manager in production (e.g., AWS Secrets Manager, HashiCorp Vault)
- **Audit logging**: All calls are logged with caller context for compliance audits
- **Error messages**: Don't expose full API responses to end users; log details server-side only
- **Rate limiting**: Implement application-level rate limiting to prevent provider lock-out
- **Input sanitization**: Validate and sanitize user input before including in prompts

## Requirements

- **PHP**: 8.2+
- **SilverStripe**: 6.1+
- **Guzzle HTTP Client**: 7.8+ (for API calls)

## License

Proprietary. See LICENSE file.

## Support

For issues, feature requests, or questions:
1. Check the [audit logs](#debugging) for diagnostic clues
2. Test provider connection in CMS
3. Verify API key and model name are correct
4. Contact your SilverStripe administrator

## Changelog

### v1.0.0 (Initial Release)
- Multi-provider gateway (OpenAI, Gemini, Anthropic)
- Unified AIResponseDTO
- AILog audit logging
- CMS provider management
- Connection testing action
- Token accounting
