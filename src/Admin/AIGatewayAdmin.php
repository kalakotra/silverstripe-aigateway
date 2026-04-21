<?php

declare(strict_types=1);

namespace Kalakotra\AIGateway\Admin;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\View\Requirements;
use Kalakotra\AIGateway\Models\AILog;
use Kalakotra\AIGateway\Models\AIProviderConfig;

/**
 * CMS "Control Room" for the AIGateway module.
 *
 * Provides two managed models:
 *   AIProviderConfig — create/edit/delete provider credentials; test connections.
 *   AILog            — readonly audit log with filterable grid.
 *
 * Access: /admin/ai-gateway/
 */
class AIGatewayAdmin extends ModelAdmin
{
    private static string $url_segment = 'ai-gateway';
    private static string $menu_title  = 'AI Gateway';
    private static string $menu_icon_class = 'font-icon-help-circled';

    /** @var array<class-string, string> */
    private static array $managed_models = [
        AIProviderConfig::class => ['title' => 'Providers'],
        AILog::class            => ['title' => 'Call Logs'],
    ];

    // =========================================================================
    // GridField customisation per model
    // =========================================================================

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);

        // Inject admin CSS once per page load
        Requirements::customCSS($this->getAdminCSS(), 'aigateway-admin');

        // Inject admin JS for the Test Connection AJAX handling
        Requirements::customScript($this->getAdminJS(), 'aigateway-admin-js');

        /** @var GridField $gridField */
        $gridField = $form->Fields()->fieldByName(
            $this->sanitiseClassName($this->modelClass)
        );

        if (!$gridField instanceof GridField) {
            return $form;
        }

        match ($this->modelClass) {
            AIProviderConfig::class => $this->configureProviderGrid($gridField),
            AILog::class            => $this->configureLogGrid($gridField),
            default                 => null,
        };

        return $form;
    }

    // =========================================================================
    // AIProviderConfig grid
    // =========================================================================

    private function configureProviderGrid(GridField $gridField): void
    {
        $config = GridFieldConfig_RecordEditor::create(itemsPerPage: 20);

        // Remove the default export button (API keys should not be exported)
        $config->removeComponentsByType(GridFieldExportButton::class);

        // Customise columns: show active badge prominently
        /** @var GridFieldDataColumns $columns */
        $columns = $config->getComponentByType(GridFieldDataColumns::class);
        $columns->setDisplayFields([
            'Label'        => 'Label',
            'ProviderName' => 'Provider',
            'ModelName'    => 'Model',
            'Category'     => 'Category',
            'ActiveBadge'  => 'Status',
            'Created'      => 'Created',
        ]);

        // Format the ActiveBadge column as HTML
        $columns->setFieldFormatting([
            'ActiveBadge' => static function ($val, AIProviderConfig $record): string {
                if ($record->IsActive) {
                    return '<span class="aigateway-badge aigateway-badge--active">&#9679; Active</span>';
                }
                return '<span class="aigateway-badge aigateway-badge--inactive">&#9675; Inactive</span>';
            },
        ]);

        // Add the Test Connection custom action
        $config->addComponent(new GridFieldTestConnectionAction());

        $gridField->setConfig($config);
    }

    // =========================================================================
    // AILog grid
    // =========================================================================

    private function configureLogGrid(GridField $gridField): void
    {
        // RecordViewer: no add / edit / delete buttons in the grid itself.
        // Delete is still available inside the record detail view via canDelete().
        $config = GridFieldConfig_RecordViewer::create(itemsPerPage: 50);

        $config->addComponents(
            new GridFieldToolbarHeader(),
            new GridFieldSortableHeader(),
            new GridFieldFilterHeader(),
            new GridFieldPaginator(50),
            // Allow deletion for log retention management
            new GridFieldDeleteAction(),
        );

        // Remove the export button — logs may contain sensitive prompt data
        $config->removeComponentsByType(GridFieldExportButton::class);

        /** @var GridFieldDataColumns $columns */
        $columns = $config->getComponentByType(GridFieldDataColumns::class);
        $columns->setDisplayFields([
            'Created'       => 'Timestamp',
            'ProviderSlug'  => 'Provider',
            'ModelName'     => 'Model',
            'PromptPreview' => 'Prompt',
            'TotalTokens'   => 'Tokens',
            'LatencyMs'     => 'ms',
            'StatusBadge'   => 'Status',
        ]);

        // StatusBadge is a DBHTMLText virtual field — cast it so the grid renders HTML
        $columns->setFieldFormatting([
            'StatusBadge' => static function ($val, AILog $record): string {
                return (string) $record->getStatusBadge()->getValue();
            },
            'LatencyMs' => static function ($val): string {
                return number_format((float) $val, 1);
            },
        ]);

        $gridField->setConfig($config);
    }

    // =========================================================================
    // Inline CSS — no external file dependency
    // =========================================================================

    private function getAdminCSS(): string
    {
        return <<<CSS
        /* ── AIGateway Admin Styles ─────────────────────────────────── */

        /* Status badges — grid column */
        .aigateway-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .aigateway-badge--success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .aigateway-badge--error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .aigateway-badge--active {
            background: #cce5ff;
            color: #004085;
            border: 1px solid #b8daff;
        }
        .aigateway-badge--inactive {
            background: #f8f9fa;
            color: #6c757d;
            border: 1px solid #dee2e6;
        }

        /* Status banner — detail view header */
        .aigateway-status-banner {
            padding: 10px 16px;
            border-radius: 4px;
            margin-bottom: 16px;
            font-size: 13px;
        }
        .aigateway-status-banner--success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        .aigateway-status-banner--error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        /* Test button column */
        .col-aigateway-test { width: 80px; text-align: center; }
        .aigateway-test-btn { min-width: 70px; }

        /* Toast notification */
        .aigateway-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 99999;
            min-width: 320px;
            max-width: 500px;
            padding: 14px 20px;
            border-radius: 6px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.18);
            font-size: 13px;
            line-height: 1.5;
            opacity: 0;
            transform: translateY(12px);
            transition: opacity 0.25s ease, transform 0.25s ease;
        }
        .aigateway-toast.is-visible {
            opacity: 1;
            transform: translateY(0);
        }
        .aigateway-toast--success {
            background: #155724;
            color: #fff;
            border-left: 5px solid #28a745;
        }
        .aigateway-toast--error {
            background: #721c24;
            color: #fff;
            border-left: 5px solid #dc3545;
        }
        .aigateway-toast__close {
            float: right;
            margin-left: 12px;
            cursor: pointer;
            opacity: 0.7;
            font-size: 16px;
            line-height: 1;
            background: none;
            border: none;
            color: inherit;
        }
        CSS;
    }

    // =========================================================================
    // Inline JS — AJAX handler for Test Connection action
    // =========================================================================

    private function getAdminJS(): string
    {
        return <<<JS
        (function () {
            'use strict';

            // ── Toast Notification ───────────────────────────────────────────

            function showToast(message, isSuccess) {
                // Remove any existing toast
                var existing = document.querySelector('.aigateway-toast');
                if (existing) { existing.remove(); }

                var toast = document.createElement('div');
                toast.className = 'aigateway-toast aigateway-toast--' + (isSuccess ? 'success' : 'error');
                toast.innerHTML =
                    '<button class="aigateway-toast__close" aria-label="Close">&times;</button>' +
                    '<strong>' + (isSuccess ? '✔ Connection OK' : '✖ Connection Failed') + '</strong>' +
                    '<br>' + escapeHtml(message);

                document.body.appendChild(toast);

                // Trigger transition
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () { toast.classList.add('is-visible'); });
                });

                // Auto-dismiss after 6 s
                var timer = setTimeout(function () { dismissToast(toast); }, 6000);

                toast.querySelector('.aigateway-toast__close').addEventListener('click', function () {
                    clearTimeout(timer);
                    dismissToast(toast);
                });
            }

            function dismissToast(toast) {
                toast.classList.remove('is-visible');
                setTimeout(function () { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 300);
            }

            function escapeHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

            // ── AJAX Test Connection ─────────────────────────────────────────

            function handleTestClick(btn) {
                var testUrl = btn.getAttribute('data-test-url');
                if (!testUrl) { return; }

                btn.disabled = true;
                btn.textContent = '…';

                fetch(testUrl, {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    showToast(data.message, data.success === true);
                })
                .catch(function (err) {
                    showToast('Request error: ' + err.message, false);
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.textContent = '⚡ Test';
                });
            }

            // ── Event delegation — survives GridField re-renders ─────────────

            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.aigateway-test-btn');
                if (btn) {
                    e.preventDefault();
                    e.stopPropagation();
                    handleTestClick(btn);
                }
            });

        }());
        JS;
    }
}
