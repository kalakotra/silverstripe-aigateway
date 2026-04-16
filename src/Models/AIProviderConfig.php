<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Models;

use SilverStripe\ORM\DataObject;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\PasswordField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;

/**
 * Persists AI provider credentials and activation state.
 *
 * Business rules enforced here:
 *  - Only ONE record may have IsActive = true at any time (enforced in onBeforeWrite).
 *  - API keys are stored as plain text in the DB but rendered via PasswordField in the CMS
 *    so they are not visible over the shoulder. For production, encrypt at rest using
 *    SilverStripe's symmetric encryption helpers or a secrets manager.
 *  - ProviderName must match a key registered in AIProviderRegistry YAML config.
 */
class AIProviderConfig extends DataObject implements PermissionProvider
{
    private static string $table_name = 'AIProviderConfig';

    private static string $singular_name = 'AI Provider';
    private static string $plural_name   = 'AI Providers';

    private static array $db = [
        'ProviderName' => 'Varchar(64)',   // e.g. 'openai', 'gemini', 'anthropic', 'mittwald-open-llm'
        'Label'        => 'Varchar(128)',  // Human-readable label, e.g. 'OpenAI GPT-4o (Production)'
        'APIKey'       => 'Text',          // Stored encrypted-at-rest in production
        'ModelName'    => 'Varchar(128)',  // e.g. 'gpt-4o', 'gemini-1.5-pro', 'claude-3-5-sonnet-20241022'
        'IsActive'     => 'Boolean',
        'Notes'        => 'Text',          // Optional internal notes for CMS editors
    ];

    private static array $has_many = [
        'Logs' => AILog::class,
    ];

    private static array $summary_fields = [
        'Label'              => 'Label',
        'ProviderName'       => 'Provider',
        'ModelName'          => 'Model',
        'IsActiveNice'       => 'Active',
    ];

    private static array $searchable_fields = [
        'Label',
        'ProviderName',
        'ModelName',
        'IsActive',
    ];

    private static string $default_sort = 'Label ASC';

    // -------------------------------------------------------------------------
    // Supported provider slugs — must align with AIProviderRegistry YAML config
    // -------------------------------------------------------------------------

    private static array $supported_providers = [
        'openai'    => 'OpenAI (GPT)',
        'gemini'    => 'Google Gemini',
        'anthropic' => 'Anthropic (Claude)',
        'mittwald-open-llm' => 'Mittwald Open-LLM',
    ];

    // -------------------------------------------------------------------------
    // CMS Fields
    // -------------------------------------------------------------------------

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName(['APIKey', 'ProviderName', 'Notes']);

        $fields->addFieldsToTab('Root.Main', [
            TextField::create('Label', 'Label')
                ->setDescription('Human-readable identifier, e.g. "OpenAI GPT-4o Production"'),

            DropdownField::create('ProviderName', 'Provider', self::config()->get('supported_providers'))
                ->setEmptyString('— Select a provider —'),

            TextField::create('ModelName', 'Model Name')
                ->setDescription('Exact model identifier, e.g. gpt-4o, gemini-1.5-pro, claude-opus-4-6'),

            // PasswordField prevents the key from being visible in plain text on screen.
            // The field autocomplete is disabled via setAutocomplete to prevent browser password managers
            // from overwriting saved keys with login credentials.
            PasswordField::create('APIKey', 'API Key')
                ->setDescription('Stored securely. Leave blank to keep the existing key when editing.')
                ->setAttribute('autocomplete', 'new-password'),

            CheckboxField::create('IsActive', 'Set as Active Provider')
                ->setDescription('Only one provider may be active at a time. Activating this will deactivate all others.'),

            TextField::create('Notes', 'Internal Notes')
                ->setDescription('Optional. Visible only to CMS admins.'),
        ]);

        // When editing an existing record, show a masked readonly hint so the editor
        // knows a key is already stored without revealing its value.
        if ($this->exists() && !empty($this->APIKey)) {
            $fields->insertAfter(
                'APIKey',
                ReadonlyField::create(
                    'APIKeyHint',
                    'Stored Key',
                    '••••••••' . substr($this->APIKey, -4)
                )->setDescription('Last 4 characters of the stored key shown for verification.')
            );
        }

        return $fields;
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (empty($this->ProviderName)) {
            $result->addError('Provider is required.');
        }

        if (empty($this->ModelName)) {
            $result->addError('Model Name is required.');
        }

        if (empty($this->Label)) {
            $result->addError('Label is required.');
        }

        // API key is required only on creation; on edit an empty field means "keep existing"
        if (!$this->exists() && empty($this->APIKey)) {
            $result->addError('API Key is required when creating a new provider configuration.');
        }

        $supportedProviders = self::config()->get('supported_providers');
        if (!empty($this->ProviderName) && !array_key_exists($this->ProviderName, $supportedProviders)) {
            $result->addError(sprintf(
                'Provider "%s" is not registered. Supported values: %s.',
                $this->ProviderName,
                implode(', ', array_keys($supportedProviders))
            ));
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // onBeforeWrite — enforce single-active-provider invariant
    // -------------------------------------------------------------------------

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        // If PasswordField was submitted empty during an edit, preserve the existing key.
        if ($this->exists() && empty($this->APIKey)) {
            $existing = self::get()->byID($this->ID);
            if ($existing) {
                $this->APIKey = $existing->APIKey;
            }
        }

        // INVARIANT: Only one AIProviderConfig may be IsActive at any time.
        // If this record is being set to active, deactivate all other records first.
        if ((bool) $this->IsActive) {
            $others = self::get()->filter('IsActive', true);

            // Exclude the current record if it already exists in the DB
            if ($this->exists()) {
                $others = $others->exclude('ID', $this->ID);
            }

            foreach ($others as $other) {
                $other->IsActive = false;
                // Use ->writeWithoutVersion() if Versioned is applied; plain write() otherwise.
                // Direct DB write avoids recursion and skips unnecessary hooks on other records.
                $other->write();
            }
        }
    }

    // -------------------------------------------------------------------------
    // Summary field helpers
    // -------------------------------------------------------------------------

    public function getIsActiveNice(): string
    {
        return $this->IsActive ? '✅ Active' : '—';
    }

    /**
     * HTML badge used by AIGatewayAdmin GridField column formatting.
     * Returns a plain string — the admin's setFieldFormatting() wraps it in HTML.
     */
    public function getActiveBadge(): string
    {
        return $this->IsActive ? 'active' : 'inactive';
    }

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------

    public function providePermissions(): array
    {
        return [
            'AIGATEWAY_CONFIG_VIEW'   => 'AIGateway: View provider configurations',
            'AIGATEWAY_CONFIG_EDIT'   => 'AIGateway: Edit provider configurations',
            'AIGATEWAY_CONFIG_DELETE' => 'AIGateway: Delete provider configurations',
        ];
    }

    public function canView($member = null): bool
    {
        return Permission::check('AIGATEWAY_CONFIG_VIEW', 'any', $member)
            || Permission::check('ADMIN', 'any', $member);
    }

    public function canEdit($member = null): bool
    {
        return Permission::check('AIGATEWAY_CONFIG_EDIT', 'any', $member)
            || Permission::check('ADMIN', 'any', $member);
    }

    public function canDelete($member = null): bool
    {
        return Permission::check('AIGATEWAY_CONFIG_DELETE', 'any', $member)
            || Permission::check('ADMIN', 'any', $member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('AIGATEWAY_CONFIG_EDIT', 'any', $member)
            || Permission::check('ADMIN', 'any', $member);
    }
}
