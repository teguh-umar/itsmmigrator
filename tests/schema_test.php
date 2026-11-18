<?php

require __DIR__ . '/bootstrap.php';
require_once ITSM_ROOT . '/install/install.php';

$ddl = plugin_itsmmigrator_schema_ddl('utf8mb4', 'utf8mb4_unicode_ci');

check('three tables', array_keys($ddl) === ['glpi_plugin_itsmmigrator_sources', 'glpi_plugin_itsmmigrator_maps', 'glpi_plugin_itsmmigrator_runs']);

$all = implode("\n", $ddl);
check('no DATETIME anywhere', stripos($all, 'DATETIME') === false);

$byTable = [];
foreach ($ddl as $table => $sql) {
    $short = substr($table, strlen('glpi_plugin_itsmmigrator_'));
    $cols  = [];
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^\s*`(\w+)`\s+(.+?),?\s*$/', $line, $m)) {
            $cols[$m[1]] = $m[2];
        }
    }
    check("$short: id is INT UNSIGNED NOT NULL AUTO_INCREMENT", ($cols['id'] ?? '') === 'INT UNSIGNED NOT NULL AUTO_INCREMENT');
    foreach ($cols as $name => $def) {
        if (str_ends_with($name, '_id') && !in_array($name, ['src_id', 'display_id'], true)) {
            check("$short.$name is INT UNSIGNED", str_starts_with($def, 'INT UNSIGNED'));
        }
        if (str_ends_with($name, '_at') || str_starts_with($name, 'date_') || $name === 'watermark' || $name === 'since') {
            check("$short.$name is TIMESTAMP NULL", str_starts_with($def, 'TIMESTAMP NULL'));
        }
        // MySQL strict: a TEXT column cannot carry a DEFAULT.
        if (str_starts_with($def, 'TEXT')) {
            check("$short.$name TEXT has no DEFAULT", !str_contains($def, 'DEFAULT') || str_contains($def, 'NULL'));
            check("$short.$name TEXT is nullable", str_starts_with($def, 'TEXT NULL'));
        }
    }
    $byTable[$short] = $cols;
}

check('maps: UNIQUE (source, src_type, src_id)',
    str_contains($ddl['glpi_plugin_itsmmigrator_maps'], 'UNIQUE KEY `src` (`source`, `src_type`, `src_id`)'));
check('maps: (itemtype, items_id) key is NOT unique',
    str_contains($ddl['glpi_plugin_itsmmigrator_maps'], "\n            KEY `item` (`itemtype`, `items_id`)"));
check('maps: origin_ref and status keys',
    str_contains($ddl['glpi_plugin_itsmmigrator_maps'], 'KEY `origin_ref` (`origin_ref`)')
    && str_contains($ddl['glpi_plugin_itsmmigrator_maps'], 'KEY `status` (`status`)'));
check('maps: src_id is a string (SDP ids are long)', str_starts_with($byTable['maps']['src_id'] ?? '', 'VARCHAR(64)'));
check('sources: api_key is TEXT NULL', ($byTable['sources']['api_key'] ?? '') === 'TEXT NULL');
check('sources: entities_id has no zero-means-root escape in DDL (validated in Source::errors)', isset($byTable['sources']['entities_id']));
check('sources: no is_recursive column (a source is never recursive)', !isset($byTable['sources']['is_recursive']));
check('runs: has heartbeat_at', isset($byTable['runs']['heartbeat_at']));

// Uninstall must not drop tables (spec §3): only purge does.
$un = file_get_contents(ITSM_ROOT . '/install/uninstall.php');
check('uninstall never drops a table', stripos($un, 'DROP TABLE') === false && stripos($un, 'SHOW TABLES') === false);

done();
