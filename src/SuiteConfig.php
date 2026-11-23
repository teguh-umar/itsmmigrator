<?php

namespace GlpiPlugin\Itsmmigrator;

use Session;

/**
 * This plugin's section on the shared "ICT Tools" tab (Setup > ICT Tools).
 * Registered in setup.php as the string 'GlpiPlugin\Itsmmigrator\SuiteConfig::section'.
 * Order 85 is the slot allocated to this plugin by the suite orchestrator.
 */
final class SuiteConfig
{
    public const ORDER = 85;

    private const LIST = '/plugins/itsmmigrator/front/config.php';

    /** The host page enforces only core `config` READ, so this plugin's own right is checked here. */
    public static function section(): ?array
    {
        if (!Session::haveRight(RightDefinition::CONFIG, READ)) {
            return null;
        }
        return [
            'title'  => __('ITSM Migrator', 'itsmmigrator'),
            'icon'   => 'ti ti-database-import',
            'order'  => self::ORDER,
            'render' => 'GlpiPlugin\Itsmmigrator\SuiteConfig::render',
        ];
    }

    public static function render(bool $fromSuite = true): void
    {
        // Global options (guard mode, report e-mail defaults) arrive with M5/M6; the per-source
        // settings stay on the plugin's own pages.
        echo '<div class="card"><div class="card-body">';
        echo '<p>' . htmlescape(__('Sources, target entities and run history are managed on the ITSM Migrator pages.', 'itsmmigrator')) . '</p>';
        echo '<a class="btn btn-outline-secondary" href="' . htmlescape(self::LIST) . '">'
            . htmlescape(__('Open sources', 'itsmmigrator')) . '</a>';
        echo '</div></div>';
    }
}
