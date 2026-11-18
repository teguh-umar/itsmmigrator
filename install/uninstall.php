<?php

use GlpiPlugin\Itsmmigrator\RightDefinition;

/**
 * The three tables are NOT dropped: dropping the maps would make a reinstall re-import everything
 * (spec §3). Only `itsmmigrator:purge --confirm` drops them. Migrated GLPI data is never deleted.
 */
function plugin_itsmmigrator_uninstall()
{
    CronTask::unregister('itsmmigrator');

    ProfileRight::deleteProfileRights(array_values(RightDefinition::names()));

    return true;
}
