<?php

namespace GlpiPlugin\Itsmmigrator;

use Session;

/**
 * This plugin's entry in the shared PGN Suite sidebar sector (hub §5).
 * Registered in setup.php as the string 'GlpiPlugin\Itsmmigrator\Menu::entry'.
 * Order 85 is the slot allocated to this plugin by the suite orchestrator.
 */
final class Menu
{
    public const ORDER = 85;

    private const BASE = '/plugins/itsmmigrator/front/';

    /** Provider for the shared Menu: null when the user may see nothing here. */
    public static function entry(): ?array
    {
        $config = Session::haveRight(RightDefinition::CONFIG, READ);
        if (!$config) {
            return null;
        }

        $links = [];
        if (Session::haveRight(RightDefinition::CONFIG, UPDATE)) {
            $links['add'] = self::BASE . 'source.form.php';
        }
        $list = self::BASE . 'config.php';

        return [
            'title'   => __('ITSM Migrator', 'itsmmigrator'),
            'icon'    => 'ti ti-database-import',
            'page'    => $list,
            'order'   => self::ORDER,
            'links'   => [],
            'options' => [
                // Key must equal the third element of Source::getSectorizedDetails().
                'source' => [
                    'title'          => Source::getTypeName(2),
                    'icon'           => Source::getIcon(),
                    'page'           => $list,
                    'links'          => $links,
                    'lists_itemtype' => Source::class,
                ],
            ],
        ];
    }

    /** @return array|false the entry, or false so the sector hides */
    public static function getMenuContent()
    {
        return self::entry() ?? false;
    }
}
