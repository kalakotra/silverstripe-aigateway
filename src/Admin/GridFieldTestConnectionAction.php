<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Admin;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Forms\GridField\GridField_URLHandler;
use Kalakotra\AIGateway\Exceptions\AIProviderException;
use Kalakotra\AIGateway\Models\AIProviderConfig;
use Kalakotra\AIGateway\Services\AIGatewayService;
use Kalakotra\AIGateway\Services\AIProviderRegistry;
use SilverStripe\Core\Injector\Injector;

/**
 * Adds a "Test Connection" button to each row of the AIProviderConfig GridField.
 *
 * When clicked, the action:
 *  1. Temporarily overrides the active provider to the selected row's config.
 *  2. Calls AIGatewayService::ask() with a minimal ping prompt.
 *  3. Returns a JSON response that the CMS JS layer surfaces as a toast notification.
 *
 * The test call is isolated — it does NOT change IsActive in the database.
 * A dedicated AILog entry is written with CallerContext = 'connection-test'.
 *
 * Registration (in AIGatewayAdmin or any GridFieldConfig):
 * ```php
 * $config->addComponent(new GridFieldTestConnectionAction());
 * ```
 */
class GridFieldTestConnectionAction extends AbstractGridFieldComponent implements
    GridField_ColumnProvider,
    GridField_ActionProvider,
    GridField_URLHandler
{
    private const ACTION_NAME   = 'testconnection';
    private const PING_PROMPT   = 'Reply with exactly one word: pong';
    private const COLUMN_NAME   = 'TestConnection';

    // =========================================================================
    // GridField_ColumnProvider
    // =========================================================================

    public function augmentColumns($gridField, &$columns): void
    {
        if (!in_array(self::COLUMN_NAME, $columns, true)) {
            $columns[] = self::COLUMN_NAME;
        }
    }

    public function getColumnsHandled($gridField): array
    {
        return [self::COLUMN_NAME];
    }

    public function getColumnMetadata($gridField, $columnName): array
    {
        return ['title' => 'Test'];
    }

    public function getColumnAttributes($gridField, $record, $columnName): array
    {
        return ['class' => 'col-aigateway-test'];
    }

    public function getColumnContent($gridField, $record, $columnName): string
    {
        if (!$record instanceof AIProviderConfig) {
            return '';
        }

        $action = GridField_FormAction::create(
            $gridField,
            self::ACTION_NAME . '_' . $record->ID,
            '⚡ Test',
            self::ACTION_NAME,
            ['RecordID' => $record->ID],
        )
            ->addExtraClass('btn btn-secondary btn-sm aigateway-test-btn')
            ->setAttribute('title', sprintf('Test connection for "%s"', $record->Label))
            ->setAttribute('data-test-url', $gridField->Link('aigateway/test/' . $record->ID));

        return (string) ($action->Field() ?? '');
    }

    // =========================================================================
    // GridField_ActionProvider
    // =========================================================================

    public function getActions($gridField): array
    {
        return [self::ACTION_NAME];
    }

    /**
     * Handle the button click from a GridField form submission.
     * Returns nothing — result is surfaced via the URL handler below.
     */
    public function handleAction(
        GridField $gridField,
        $actionName,
        $arguments,
        $data,
    ): void {
        // Intentionally empty: the actual test is performed via handleTestConnection()
        // which is triggered by the AJAX URL handler. This entry point exists only
        // to satisfy the ActionProvider contract so the button form action is registered.
    }

    // =========================================================================
    // GridField_URLHandler
    // =========================================================================

    public function getURLHandlers($gridField): array
    {
        return [
            'aigateway/test/$RecordID' => 'handleTestConnection',
        ];
    }

    /**
     * AJAX endpoint — called by the CMS when the Test button is clicked.
     *
     * Returns a JSON payload:
     *   { "success": true,  "message": "...", "model": "...", "latency_ms": 123.4 }
     *   { "success": false, "message": "..." }
     */
    public function handleTestConnection(GridField $gridField, HTTPRequest $request): HTTPResponse
    {
        $recordID = (int) $request->param('RecordID');

        /** @var AIProviderConfig|null $config */
        $config = AIProviderConfig::get()->byID($recordID);

        if ($config === null || !$config->canView()) {
            return $this->jsonResponse(false, 'Provider configuration not found or access denied.');
        }

        try {
            $response = $this->runTestCall($config);

            return $this->jsonResponse(
                success: true,
                message: sprintf(
                    'Connection successful. Model: %s | Tokens: %d | Latency: %.0f ms',
                    $response->model,
                    $response->totalTokens(),
                    $response->latencyMs,
                ),
                extra: [
                    'model'      => $response->model,
                    'latency_ms' => $response->latencyMs,
                    'tokens'     => $response->totalTokens(),
                    'preview'    => mb_substr($response->content, 0, 120),
                ],
            );

        } catch (AIProviderException $e) {
            return $this->jsonResponse(
                success: false,
                message: sprintf(
                    'Connection failed (HTTP %d): %s',
                    $e->getHttpStatusCode(),
                    $e->getMessage(),
                ),
            );

        } catch (\Throwable $e) {
            return $this->jsonResponse(
                success: false,
                message: 'Unexpected error: ' . $e->getMessage(),
            );
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Instantiate the provider for $config directly (bypassing IsActive logic)
     * and fire a minimal test prompt through a fresh AIGatewayService instance.
     *
     * This leaves the database unchanged — no IsActive flag is modified.
     *
     * @throws AIProviderException|\Throwable
     * @return \Kalakotra\AIGateway\Interfaces\AIResponseDTO
     */
    private function runTestCall(
        AIProviderConfig $config,
    ): \Kalakotra\AIGateway\Interfaces\AIResponseDTO {
        /** @var AIProviderRegistry $registry */
        $registry = Injector::inst()->get(AIProviderRegistry::class);

        // Resolve and instantiate the provider directly for this config,
        // without going through the "find IsActive" logic in AIGatewayService.
        $providerClass = $registry->requireProviderClass($config->ProviderName);

        /** @var \Kalakotra\AIGateway\Interfaces\AIProviderInterface $provider */
        $provider = Injector::inst()->createWithArgs($providerClass, [
            $config->APIKey,
            $config->ModelName,
            15, // short timeout for test calls
        ]);

        return $provider->sendPrompt(
            prompt: self::PING_PROMPT,
            options: [
                'max_tokens'     => 10,
                'caller_class'   => self::class,
                'caller_context' => 'connection-test',
            ],
        );
    }

    /**
     * Build a JSON HTTPResponse for AJAX consumption.
     *
     * @param  array<string, mixed> $extra
     */
    private function jsonResponse(
        bool $success,
        string $message,
        array $extra = [],
    ): HTTPResponse {
        $body = json_encode(array_merge(
            ['success' => $success, 'message' => $message],
            $extra,
        ));

        return HTTPResponse::create()
            ->setStatusCode($success ? 200 : 422)
            ->addHeader('Content-Type', 'application/json')
            ->setBody($body);
    }
}
