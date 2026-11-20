<?php

/**
 * Itsmmigrator (ITSM Migrator) - external ITSM data into GLPI 11 (PGN GLPI Suite plugin)
 *
 * @author  ICT
 * @license GPL-3.0-or-later
 */

define('PLUGIN_ITSMMIGRATOR_VERSION', '0.1.0-rc18');
define('PLUGIN_ITSMMIGRATOR_MIN_GLPI', '11.0.0');
define('PLUGIN_ITSMMIGRATOR_MAX_GLPI', '11.0.99');

function plugin_version_itsmmigrator()
{
    return [
        'name'         => 'ITSM Migrator',
        'version'      => PLUGIN_ITSMMIGRATOR_VERSION,
        'author'       => 'ICT',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_ITSMMIGRATOR_MIN_GLPI,
                'max' => PLUGIN_ITSMMIGRATOR_MAX_GLPI,
            ],
            'php'  => [
                'exts' => ['curl' => ['required' => true], 'json' => ['required' => true]],
            ],
        ],
    ];
}

function plugin_itsmmigrator_check_prerequisites()
{
    return true;
}

function plugin_itsmmigrator_check_config()
{
    return true;
}

function plugin_init_itsmmigrator()
{
    global $PLUGIN_HOOKS;

    // GLPI 11's CheckCsrfListener validates + consumes CSRF tokens - never checkCSRF() ourselves.
    $PLUGIN_HOOKS['csrf_compliant']['itsmmigrator'] = true;

    // Only GLPIKey::getFields() reads this, to re-encrypt the column when the GLPI key is rotated.
    // It neither encrypts nor masks: Source encrypts, the form never renders the value.
    $PLUGIN_HOOKS['secured_fields']['itsmmigrator'] = ['glpi_plugin_itsmmigrator_sources.api_key'];

    // Shared suite classes (hub §5): the first plugin that loads supplies each class.
    if (!class_exists(\GlpiPlugin\Pgnsuite\Menu::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/Menu.php';
    }
    \GlpiPlugin\Pgnsuite\Menu::register('itsmmigrator');
    $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\Menu::HOOK]['itsmmigrator'] = 'GlpiPlugin\Itsmmigrator\Menu::entry';

    if (!class_exists(\GlpiPlugin\Pgnsuite\ConfigTab::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/ConfigTab.php';
    }
    \GlpiPlugin\Pgnsuite\ConfigTab::register();
    // The wrench in Setup > Plugins opens the shared settings page on this plugin's section.
    $PLUGIN_HOOKS['config_page']['itsmmigrator'] = 'front/pgnsuite.settings.php?section=itsmmigrator';
    $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\ConfigTab::HOOK]['itsmmigrator'] = 'GlpiPlugin\Itsmmigrator\SuiteConfig::section';

    // No AssetTab. The "External references" tab sits on Ticket, Problem and Change; it shows only when the item has map rows.
    Plugin::registerClass(\GlpiPlugin\Itsmmigrator\Tab\ExternalReferenceTab::class, ['addtabon' => ['Ticket', 'Problem', 'Change']]);

    // The rights matrix on Administration > Profiles.
    Plugin::registerClass(\GlpiPlugin\Itsmmigrator\Profile::class, ['addtabon' => 'Profile']);
}
