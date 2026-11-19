<?php

use GlpiPlugin\Itsmmigrator\Cron\SyncTask;
use GlpiPlugin\Itsmmigrator\RightDefinition;
use GlpiPlugin\Itsmmigrator\RightSeeder;

/**
 * DDL for the three tables, keyed by table name. Pure, so tests can assert on it.
 * Suite schema rules (hub §4): keys INT UNSIGNED written literally, dates TIMESTAMP NULL only,
 * no TEXT column with a DEFAULT, never '' into a TIMESTAMP.
 *
 * @return array<string, string>
 */
function plugin_itsmmigrator_schema_ddl(string $charset, string $collation): array
{
    $engine = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";

    return [
        'glpi_plugin_itsmmigrator_sources' => "CREATE TABLE `glpi_plugin_itsmmigrator_sources` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `adapter` VARCHAR(50) NOT NULL DEFAULT '',
            `base_url` VARCHAR(255) NOT NULL DEFAULT '',
            `api_key` TEXT NULL,
            `ca_bundle_path` VARCHAR(255) NULL DEFAULT NULL,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `tech_profiles_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `selfservice_profiles_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `status_map` TEXT NULL,
            `priority_map` TEXT NULL,
            `type_map` TEXT NULL,
            `options` TEXT NULL,
            `schedule` VARCHAR(20) NOT NULL DEFAULT 'manual',
            `watermark` TIMESTAMP NULL DEFAULT NULL,
            `is_active` TINYINT NOT NULL DEFAULT 1,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `adapter` (`adapter`)
        ) $engine",

        // KEY (itemtype, items_id) is NOT unique: an FS ticket and an SDP request may point to the
        // same GLPI ticket (spec §3).
        'glpi_plugin_itsmmigrator_maps' => "CREATE TABLE `glpi_plugin_itsmmigrator_maps` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `sources_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `source` VARCHAR(20) NOT NULL DEFAULT '',
            `src_type` VARCHAR(50) NOT NULL DEFAULT '',
            `src_id` VARCHAR(255) NOT NULL DEFAULT '',
            `display_id` VARCHAR(64) NULL DEFAULT NULL,
            `itemtype` VARCHAR(100) NOT NULL DEFAULT '',
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `action` VARCHAR(20) NOT NULL DEFAULT 'created',
            `status` VARCHAR(20) NOT NULL DEFAULT 'in_progress',
            `sync_owned` TINYINT NOT NULL DEFAULT 0,
            `origin_ref` VARCHAR(100) NULL DEFAULT NULL,
            `content_hash` CHAR(40) NULL DEFAULT NULL,
            `error` TEXT NULL,
            `src_updated_at` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `src` (`source`, `src_type`, `src_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `origin_ref` (`origin_ref`),
            KEY `status` (`status`),
            KEY `sources_type` (`sources_id`, `src_type`)
        ) $engine",

        'glpi_plugin_itsmmigrator_runs' => "CREATE TABLE `glpi_plugin_itsmmigrator_runs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `sources_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `runner` VARCHAR(10) NOT NULL DEFAULT 'cli',
            `modules` VARCHAR(255) NOT NULL DEFAULT '',
            `dry_run` TINYINT NOT NULL DEFAULT 0,
            `since` TIMESTAMP NULL DEFAULT NULL,
            `started_at` TIMESTAMP NULL DEFAULT NULL,
            `heartbeat_at` TIMESTAMP NULL DEFAULT NULL,
            `finished_at` TIMESTAMP NULL DEFAULT NULL,
            `status` VARCHAR(10) NOT NULL DEFAULT 'running',
            `counters` TEXT NULL,
            `message` TEXT NULL,
            PRIMARY KEY (`id`),
            KEY `sources_id` (`sources_id`),
            KEY `status` (`status`)
        ) $engine",
    ];
}

function plugin_itsmmigrator_install()
{
    global $DB;

    // Existing tables are kept: a reinstall must never re-import (spec §3).
    $ddl = plugin_itsmmigrator_schema_ddl(DBConnection::getDefaultCharset(), DBConnection::getDefaultCollation());
    foreach ($ddl as $table => $sql) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery($sql);
        }
    }

    // rc17: a category path is the source id of a category and can be longer than 64 characters (the first real run failed on
    // one). Widening is idempotent and keeps every row.
    $col = $DB->request(['SELECT' => ['CHARACTER_MAXIMUM_LENGTH AS len'], 'FROM' => 'information_schema.COLUMNS', 'WHERE' => ['TABLE_SCHEMA' => $DB->dbdefault, 'TABLE_NAME' => 'glpi_plugin_itsmmigrator_maps', 'COLUMN_NAME' => 'src_id']])->current();
    if ($col !== null && (int) $col['len'] < 255) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_itsmmigrator_maps` MODIFY `src_id` VARCHAR(255) NOT NULL DEFAULT ''");
    }

    // Rights created in THIS run. An existing right keeps what an admin set in the matrix.
    $all_names  = array_values(RightDefinition::names());
    $new_rights = array_values(array_diff($all_names, array_keys(ProfileRight::getAllPossibleRights())));
    if ($new_rights !== []) {
        ProfileRight::addProfileRights($new_rights);
    }

    // All profiles from the DB - never $_SESSION (a CLI install has none).
    $profiles = [];
    foreach ($DB->request(['SELECT' => ['id', 'interface'], 'FROM' => 'glpi_profiles']) as $row) {
        $profiles[(int) $row['id']] = ['interface' => (string) $row['interface'], 'config' => 0];
    }
    foreach ($DB->request([
        'SELECT' => ['profiles_id', 'name', 'rights'],
        'FROM'   => 'glpi_profilerights',
        'WHERE'  => ['name' => 'config'],
    ]) as $row) {
        $pid = (int) $row['profiles_id'];
        if (isset($profiles[$pid])) {
            $profiles[$pid]['config'] = (int) $row['rights'];
        }
    }

    // Not behind an "any new rights?" check: the Super-Admin grant runs on every install and upgrade.
    $existing = array_values(array_diff($all_names, $new_rights));
    foreach (RightSeeder::seed($existing, $profiles) as $pid => $rights) {
        ProfileRight::updateProfileRights($pid, $rights);
    }

    // Weekly, external mode: never from a web request. register() is idempotent; an upgrade keeps
    // the admin's chosen frequency. The Sunday 01:00-05:00 WIB window is set in M5.
    CronTask::register(SyncTask::class, SyncTask::TASK, WEEK_TIMESTAMP, [
        'comment' => 'Weekly delta sync of every active ITSM source with schedule=weekly',
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => 0, // off until M5: there is no sync engine yet
    ]);

    return true;
}
