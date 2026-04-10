<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Models;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;

/**
 * Immutable audit log for every call routed through AIGatewayService.
 *
 * Records are written after each sendPrompt() call regardless of success or
 * failure. They are intentionally not editable through the CMS (canEdit()
 * always returns false).
 *
 * Retention policy (pruning records older than N days) should be implemented
 * as a BuildTask or cron job at the project level.
 */
class AILog extends DataObject implements PermissionProvider
{
    private static string $table_name = 'AILog';

    private static string $singular_name = 'AI Log Entry';
    private static string $plural_name   = 'AI Log Entries';

    private static array $db = [
        // Provider / model identity
        'ProviderSlug' => 'Varchar(64)',
        'ModelName'    => 'Varchar(128)',

        // Prompt — preview for grid, full for detail view
        'PromptPreview' => 'Varchar(512)',
        'PromptFull'    => 'Text',

        // Response
        'ResponseText' => 'Text',
        'RawResponse'  => 'Text',   // JSON-encoded full API response body

        // Token accounting
        'InputTokens'  => 'Int',
        'OutputTokens' => 'Int',
        'TotalTokens'  => 'Int',

        // Performance
        'LatencyMs' => 'Decimal(10,2)',

        // Success / failure
        'IsError'      => 'Boolean',
        'ErrorMessage' => 'Text',

        // Calling context (which module / page triggered this call)
        'CallerClass'   => 'Varchar(255)',
        'CallerContext' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'ProviderConfig' => AIProviderConfig::class,
    ];

    // 'StatusBadge' is a virtual field resolved via getStatusBadge()
    private static array $summary_fields = [
        'Created'       => 'Time',
        'ProviderSlug'  => 'Provider',
        'ModelName'     => 'Model',
        'PromptPreview' => 'Prompt (preview)',
        'TotalTokens'   => 'Tokens',
        'LatencyMs'     => 'Latency (ms)',
        'StatusBadge'   => 'Status',
    ];

    private static array $searchable_fields = [
        'ProviderSlug',
        'ModelName',
        'IsError',
        'CallerClass',
    ];

    private static string $default_sort = 'Created DESC';

    // =========================================================================
    // Factory methods — called by AIGatewayService, never via CMS
    // =========================================================================

    /**
     * Create and persist a success log entry from an AIResponseDTO.
     *
     * @param  \Kalakotra\AIGateway\Interfaces\AIResponseDTO $response
     * @param  string                                         $prompt
     * @param  AIProviderConfig|null                          $providerConfig
     * @param  string                                         $callerClass
     * @param  string                                         $callerContext
     */
    public static function createFromResponse(
        \Kalakotra\AIGateway\Interfaces\AIResponseDTO $response,
        string $prompt,
        ?AIProviderConfig $providerConfig = null,
        string $callerClass = '',
        string $callerContext = '',
    ): static {
        $log = static::create();
        $log->ProviderSlug   = $response->providerSlug;
        $log->ModelName      = $response->model;
        $log->PromptPreview  = mb_substr($prompt, 0, 512);
        $log->PromptFull     = $prompt;
        $log->ResponseText   = $response->content;
        $log->RawResponse    = json_encode($response->rawResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $log->InputTokens    = $response->inputTokens;
        $log->OutputTokens   = $response->outputTokens;
        $log->TotalTokens    = $response->totalTokens();
        $log->LatencyMs      = $response->latencyMs;
        $log->IsError        = false;
        $log->CallerClass    = $callerClass;
        $log->CallerContext  = $callerContext;

        if ($providerConfig) {
            $log->ProviderConfigID = $providerConfig->ID;
        }

        $log->write();
        return $log;
    }

    /**
     * Create and persist an error log entry from a caught exception.
     *
     * @param  \Throwable            $exception
     * @param  string                $prompt
     * @param  string                $providerSlug
     * @param  string                $modelName
     * @param  AIProviderConfig|null $providerConfig
     * @param  string                $callerClass
     * @param  string                $callerContext
     */
    public static function createFromException(
        \Throwable $exception,
        string $prompt,
        string $providerSlug,
        string $modelName = '',
        ?AIProviderConfig $providerConfig = null,
        string $callerClass = '',
        string $callerContext = '',
    ): static {
        $log = static::create();
        $log->ProviderSlug   = $providerSlug;
        $log->ModelName      = $modelName;
        $log->PromptPreview  = mb_substr($prompt, 0, 512);
        $log->PromptFull     = $prompt;
        $log->ResponseText   = '';
        $log->RawResponse    = '';
        $log->InputTokens    = 0;
        $log->OutputTokens   = 0;
        $log->TotalTokens    = 0;
        $log->LatencyMs      = 0.0;
        $log->IsError        = true;
        $log->ErrorMessage   = sprintf('[%s] %s', $exception::class, $exception->getMessage());
        $log->CallerClass    = $callerClass;
        $log->CallerContext  = $callerContext;

        if ($providerConfig) {
            $log->ProviderConfigID = $providerConfig->ID;
        }

        $log->write();
        return $log;
    }

    // =========================================================================
    // CMS Fields — all readonly; RawResponse in dedicated textarea
    // =========================================================================

    public function getCMSFields(): FieldList
    {
        $fields = FieldList::create(TabSet::create('Root'));

        // ── Tab: Summary ──────────────────────────────────────────────────────
        $fields->addFieldsToTab('Root.Main', [
            LiteralField::create('StatusBanner', $this->buildStatusBanner()),

            ReadonlyField::create('Created',       'Timestamp'),
            ReadonlyField::create('ProviderSlug',  'Provider'),
            ReadonlyField::create('ModelName',     'Model'),
            ReadonlyField::create('CallerClass',   'Called By'),
            ReadonlyField::create('CallerContext', 'Context'),
        ]);

        $fields->addFieldsToTab('Root.Tokens & Performance', [
            ReadonlyField::create('TotalTokens',  'Total Tokens'),
            ReadonlyField::create('InputTokens',  'Input Tokens'),
            ReadonlyField::create('OutputTokens', 'Output Tokens'),
            ReadonlyField::create('LatencyMs',    'Latency (ms)'),
        ]);

        // ── Tab: Prompt ───────────────────────────────────────────────────────
        $fields->addFieldsToTab('Root.Prompt', [
            TextareaField::create('PromptFull', 'Full Prompt')
                ->setReadonly(true)
                ->setRows(12),
        ]);

        // ── Tab: Response ─────────────────────────────────────────────────────
        $fields->addFieldsToTab('Root.Response', [
            TextareaField::create('ResponseText', 'Response Text')
                ->setReadonly(true)
                ->setRows(12),

            TextareaField::create('RawResponse', 'Raw API Response (JSON)')
                ->setReadonly(true)
                ->setRows(18)
                ->setDescription('Full decoded response body as returned by the provider API.'),
        ]);

        // ── Tab: Error (conditional) ──────────────────────────────────────────
        if ($this->IsError) {
            $fields->addFieldsToTab('Root.Error', [
                TextareaField::create('ErrorMessage', 'Error Details')
                    ->setReadonly(true)
                    ->setRows(8),
            ]);
        }

        return $fields;
    }

    // =========================================================================
    // Virtual / computed fields
    // =========================================================================

    /**
     * HTML badge for the GridField 'Status' column.
     * Rendered by SilverStripe because summary_fields maps 'StatusBadge' to the column.
     * The DBHTMLText return type signals to SS that this value is safe HTML.
     */
    public function getStatusBadge(): DBHTMLText
    {
        if ($this->IsError) {
            $html = '<span class="aigateway-badge aigateway-badge--error">&#10007; Error</span>';
        } else {
            $html = '<span class="aigateway-badge aigateway-badge--success">&#10003; OK</span>';
        }

        $field = DBHTMLText::create();
        $field->setValue($html);
        return $field;
    }

    /**
     * Build a prominent status banner for the detail view header.
     */
    private function buildStatusBanner(): string
    {
        if ($this->IsError) {
            return sprintf(
                '<div class="aigateway-status-banner aigateway-status-banner--error">'
                . '<strong>&#10007; Error</strong> — This call failed. See the Error tab for details.'
                . '</div>'
            );
        }

        return sprintf(
            '<div class="aigateway-status-banner aigateway-status-banner--success">'
            . '<strong>&#10003; Success</strong> — %d tokens used in %.2f ms.'
            . '</div>',
            (int) $this->TotalTokens,
            (float) $this->LatencyMs,
        );
    }

    // =========================================================================
    // Permissions
    // =========================================================================

    public function providePermissions(): array
    {
        return [
            'AIGATEWAY_LOG_VIEW'   => 'AIGateway: View AI call logs',
            'AIGATEWAY_LOG_DELETE' => 'AIGateway: Delete AI call logs (for data retention)',
        ];
    }

    public function canView($member = null): bool
    {
        return Permission::check('AIGATEWAY_LOG_VIEW', 'any', $member)
            || Permission::check('ADMIN', 'any', $member);
    }

    /** Audit logs are immutable — no CMS editing. */
    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return Permission::check('AIGATEWAY_LOG_DELETE', 'any', $member)
            || Permission::check('ADMIN', 'any', $member);
    }

    /** Logs are created programmatically only. */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }
}
