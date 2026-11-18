<?php

namespace GlpiPlugin\Itsmmigrator;

use CommonDBTM;

/**
 * Run history: one row per engine run (CLI, web or cron). The Engine and Lock arrive in M2.
 */
class Run extends CommonDBTM
{
    public static $rightname = RightDefinition::RUN;

    public const STATUSES = ['running', 'success', 'partial', 'failed', 'aborted'];
    public const RUNNERS  = ['cli', 'web', 'cron'];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_itsmmigrator_runs';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Run', 'Runs', $nb, 'itsmmigrator');
    }

    public static function getIcon()
    {
        return 'ti ti-player-play';
    }
}
