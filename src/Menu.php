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

    /**
     * Provider for the shared Menu: null when the user may see nothing here.
     * Sources need the config right; the Run page and the Needs review list need the run right.
     * The two are independent, as in the other suite plugins.
     */
    public static function entry(): ?array
    {
        $config = Session::haveRight(RightDefinition::CONFIG, READ);
        $run    = Session::haveRight(RightDefinition::RUN, READ);
        if (!$config && !$run) {
            return null;
        }

        $options = [];
        $list    = self::BASE . 'config.php';
        if ($config) {
            $links = [];
            if (Session::haveRight(RightDefinition::CONFIG, UPDATE)) {
                $links['add'] = self::BASE . 'source.form.php';
            }
            // Key must equal the third element of Source::getSectorizedDetails().
            $options['source'] = [
                'title'          => Source::getTypeName(2),
                'icon'           => Source::getIcon(),
                'page'           => $list,
                'links'          => $links,
                'lists_itemtype' => Source::class,
            ];
        }
        if ($run) {
            $options['run']    = ['title' => __('Runs', 'itsmmigrator'), 'icon' => 'ti ti-player-play', 'page' => self::BASE . 'run.php', 'links' => []];
            $options['review'] = ['title' => __('Needs review', 'itsmmigrator'), 'icon' => 'ti ti-user-question', 'page' => self::BASE . 'review.php', 'links' => []];
        }

        return [
            'title'   => __('ITSM Migrator', 'itsmmigrator'),
            'icon'    => 'ti ti-database-import',
            'page'    => $config ? $list : self::BASE . 'run.php',
            'order'   => self::ORDER,
            'links'   => [],
            'options' => $options,
        ];
    }

    /** @return array|false the entry, or false so the sector hides */
    public static function getMenuContent()
    {
        return self::entry() ?? false;
    }
}
