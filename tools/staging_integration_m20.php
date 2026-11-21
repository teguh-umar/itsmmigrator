<?php
// M20 integration check: the import rule "an imported ticket follows its source status forward" (rc33) through the real Engine, TicketWriter and GLPI core.
// STAGING ONLY. A SYNTHETIC Freshservice (fake HTTP transport): 1 ticket whose status and updated date the tool changes. Its own entity and source;
// deletes only by those ids. Run it only when no run is active.
// It never touches source 33 or entity 81.
// Usage: php -c <php.ini> tools/staging_integration_m16.php [path-to-glpi-root]
$root = $argv[1] ?? 'C:/glpi-staging/app13/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('staging');
$kernel->boot();
global $DB;
$_SESSION['glpiID'] = 2; $_SESSION['glpi_use_mode'] = 0; $_SESSION['glpiname'] = 'glpi'; $_SESSION['glpiis_ids_visible'] = 0;
$prof = new Profile(); $prof->getFromDB(4); $_SESSION['glpiactiveprofile'] = $prof->fields + ['id' => 4];
$_SESSION['glpiactiveentities'] = [0]; $_SESSION['glpiactive_entity'] = 0;

use GlpiPlugin\Itsmmigrator\Adapter\AdapterFactory;
use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceAdapter;
use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceClient;
use GlpiPlugin\Itsmmigrator\Engine;

$pass = 0; $fail = 0;
function chk(string $l, bool $ok, string $d = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "PASS $l\n"; } else { $fail++; echo "FAIL $l $d\n"; } }
$count = fn (string $t, array $w = []) => countElementsInTable($t, $w);
if ($DB->request(['FROM' => 'glpi_plugin_itsmmigrator_runs', 'WHERE' => ['status' => 'running']])->current() !== null) { echo "A run is active: not running.\n"; exit(2); }

// Every id this tool creates is written to a journal BEFORE the step that can fail, so a crashed run is cleaned up by id
// (never by name) by the next run of this tool. Only these ids are ever deleted.
$journalFile = sys_get_temp_dir() . '/itsm_m20_ids.json';
$journal = ['entity' => 0, 'source' => 0, 'users' => [], 'maps' => []];
$save = function () use (&$journal, $journalFile) { file_put_contents($journalFile, json_encode($journal)); };
$purge = function (array $j) use ($DB) {
    $e = (int) ($j['entity'] ?? 0); $sid = (int) ($j['source'] ?? 0);
    if ($e > 0) {
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $e]]) as $t) {
            foreach (['glpi_ticketvalidations', 'glpi_tickets_users', 'glpi_groups_tickets'] as $tb) { $DB->delete($tb, ['tickets_id' => $t['id']]); }
            $DB->delete('glpi_tickets', ['id' => $t['id']]);
        }
    }
    if ($sid > 0) { $DB->delete('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid]); $DB->delete('glpi_plugin_itsmmigrator_runs', ['sources_id' => $sid]); $DB->delete('glpi_plugin_itsmmigrator_sources', ['id' => $sid]); }
    foreach ((array) ($j['maps'] ?? []) as $m) { $DB->delete('glpi_plugin_itsmmigrator_maps', ['source' => 'freshservice', 'src_type' => 'user', 'src_id' => $m]); }
    foreach ((array) ($j['users'] ?? []) as $u) { (new User())->delete(['id' => (int) $u], true); $DB->delete('glpi_profiles_users', ['users_id' => (int) $u]); }
    if ($e > 0) { $DB->delete('glpi_entities', ['id' => $e]); }
    if (isset($j['limit'])) { Config::setConfigurationValues('core', ['document_max_size' => (int) $j['limit']]); }
};
if (is_file($journalFile)) { $old = json_decode((string) file_get_contents($journalFile), true); if (is_array($old)) { $purge($old); echo "cleaned up a crashed earlier run by its journal\n"; } @unlink($journalFile); }

$tag = 'itsmtest' . substr(md5((string) microtime(true)), 0, 6);
$entity = (new Entity())->add(['name' => "$tag-target", 'entities_id' => 0]);
$journal['entity'] = $entity; $save();
$_SESSION['glpiactiveentities'] = [0, $entity];
$now = date('Y-m-d H:i:s');
$DB->insert('glpi_plugin_itsmmigrator_sources', ['name' => "$tag-src", 'adapter' => 'freshservice', 'base_url' => 'https://fs.example.test', 'api_key' => (new GLPIKey())->encrypt('k'),
    'entities_id' => $entity, 'schedule' => 'manual', 'is_active' => 1, 'date_creation' => $now, 'date_mod' => $now]);
$sid = (int) $DB->insertId();
$journal['source'] = $sid; $save();
$fresh = function (array $options = []) use ($sid) { global $DB; $r = $DB->request(['FROM' => 'glpi_plugin_itsmmigrator_sources', 'WHERE' => ['id' => $sid]])->current(); unset($r['api_key']); $r['options'] = $options === [] ? null : json_encode($options); return $r; };
$wm = fn () => $DB->request(['SELECT' => ['watermark'], 'FROM' => 'glpi_plugin_itsmmigrator_sources', 'WHERE' => ['id' => $sid]])->current()['watermark'];
$tickets = fn () => $count('glpi_tickets', ['entities_id' => $entity]);
$wipe = function () use ($DB, $entity, $sid) {
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]]) as $r) { $DB->delete('glpi_ticketvalidations', ['tickets_id' => $r['id']]); $DB->delete('glpi_tickets_users', ['tickets_id' => $r['id']]); $DB->delete('glpi_groups_tickets', ['tickets_id' => $r['id']]); $DB->delete('glpi_tickets', ['id' => $r['id']]); }
    $DB->delete('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid]);
    $DB->update('glpi_plugin_itsmmigrator_sources', ['watermark' => null], ['id' => $sid]);
};




const TID = 8_700_000_020;
$fs = ['status' => 3, 'updated' => '2026-03-02T00:00:00Z'];
$transport = function (string $url, array $h, ?string $sink) use (&$fs): array {
    $ok = fn (string $b) => ['status' => 200, 'headers' => [], 'body' => $b];
    if (str_contains($url, '/ticket_form_fields')) { return $ok(json_encode(['ticket_fields' => [['name' => 'status', 'choices' => [['id' => 2, 'value' => 'Open'], ['id' => 3, 'value' => 'Pending'], ['id' => 4, 'value' => 'Resolved'], ['id' => 5, 'value' => 'Closed'], ['id' => 7, 'value' => 'Waiting on Approval']]]]])); }
    if (preg_match('#/tickets/' . TID . '(\?|$)#', $url)) { return $ok(json_encode(['ticket' => ['id' => TID, 'attachments' => []]])); }
    if (preg_match('#/tickets/' . TID . '/#', $url)) { return $ok('{"conversations":[],"tasks":[],"time_entries":[]}'); }
    if (preg_match('#/api/v2/tickets\?#', $url)) {
        return $ok(json_encode(['tickets' => [['id' => TID, 'subject' => 's', 'description' => '<p>d</p>', 'status' => $fs['status'], 'priority' => 2, 'type' => 'Incident', 'created_at' => '2026-03-01T00:00:00Z', 'updated_at' => $fs['updated']]]]));
    }
    return $ok('{}');
};
$mkAdapter = fn () => new FreshserviceAdapter(new FreshserviceClient('https://fs.example.test', 'k', $transport, fn (float $s) => null, 0.0), 1);
AdapterFactory::setOverride(fn (array $s, string $k) => $mkAdapter());
$log = []; $logger = function (string $m) use (&$log) { $log[] = $m; };
$tid = fn () => (int) ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]])->current()['id'] ?? 0);
$row = fn () => $DB->request(['FROM' => 'glpi_tickets', 'WHERE' => ['id' => $tid()]])->current();
$notes = fn () => $count('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $tid(), 'is_private' => 1, ['content' => ['LIKE', '%Status moved forward by the importer%']]]);
$run = function (array $flags = []) use ($mkAdapter, $fresh, $logger) { return (new Engine($mkAdapter()))->run($fresh(), 'cli', ['tickets'], $flags, $logger); };
$det = fn (array $res, string $k) => (int) ($res['details']['status'][$k] ?? 0);

// ---- 1. first import: source Pending -> GLPI Pending (4), no rule note (a new ticket goes through the normal path)
$run();
chk('first import: ticket created as Pending (4), no "moved forward" note', $tickets() === 1 && (int) $row()['status'] === 4 && $notes() === 0);

// ---- 2. the source now says Waiting on Approval (a custom label): GLPI Pending -> Approval (10), one private note
$fs = ['status' => 7, 'updated' => '2026-03-10T00:00:00Z'];
$res = $run();
chk('Waiting on Approval: GLPI status 10, ONE private note, counted advanced', (int) $row()['status'] === 10 && $notes() === 1 && $det($res, 'advanced') === 1, json_encode($res['details'] ?? []));
// ---- 3. same again: nothing more
$res = $run();
chk('same source status again: status stays 10, still one note, nothing advanced', (int) $row()['status'] === 10 && $notes() === 1 && $det($res, 'advanced') === 0);
// ---- 4. the source goes back to Pending: GLPI never moves backwards
$fs = ['status' => 3, 'updated' => '2026-03-12T00:00:00Z'];
$res = $run();
chk('source back to Pending: GLPI stays Approval (10), never backwards', (int) $row()['status'] === 10 && $notes() === 1 && $det($res, 'advanced') === 0);
// ---- 5. Resolved: Approval -> Solved with a solve date
$fs = ['status' => 4, 'updated' => '2026-03-20T00:00:00Z'];
$res = $run();
chk('Resolved: GLPI Solved (5), solvedate set, two notes in all, counted', (int) $row()['status'] === 5 && !empty($row()['solvedate']) && $notes() === 2 && $det($res, 'advanced') === 1, json_encode($res['details'] ?? []));
// ---- 6. Closed in the source: a Solved ticket is left alone
$fs = ['status' => 5, 'updated' => '2026-03-25T00:00:00Z'];
$res = $run();
chk('source Closed: a GLPI Solved ticket is not touched', (int) $row()['status'] === 5 && $notes() === 2 && $det($res, 'advanced') === 0);

// ---- 7. a GLPI user edited the ticket after the source's last update: the rule leaves it
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]]) as $r) { $DB->delete('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $r['id']]); }
$wipe();
$fs = ['status' => 3, 'updated' => '2026-03-02T00:00:00Z'];
$run();
$DB->update('glpi_tickets', ['date_mod' => date('Y-m-d H:i:s')], ['id' => $tid()]);
$fs = ['status' => 7, 'updated' => '2026-03-10T00:00:00Z'];
$res = $run();
chk('edited in GLPI after the source update: status stays Pending (4), no note, counted kept_edited_in_glpi', (int) $row()['status'] === 4 && $notes() === 0 && $det($res, 'kept_edited_in_glpi') === 1, json_encode($res['details'] ?? []));

// ---- 8. a dry run writes nothing
$wipe();
$fs = ['status' => 3, 'updated' => '2026-03-02T00:00:00Z'];
$run();
$fs = ['status' => 4, 'updated' => '2026-03-20T00:00:00Z'];
$res = $run(['dry_run' => true]);
chk('dry run: status unchanged (4), no note, counted would_advance', (int) $row()['status'] === 4 && $notes() === 0 && $det($res, 'would_advance') === 1, json_encode($res['details'] ?? []));

// ---- cleanup (only what this tool made)
AdapterFactory::setOverride(null);
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]]) as $r) { $DB->delete('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $r['id']]); }
$wipe();
$DB->delete('glpi_plugin_itsmmigrator_runs', ['sources_id' => $sid]);
$DB->delete('glpi_plugin_itsmmigrator_sources', ['id' => $sid]);
$DB->delete('glpi_entities', ['id' => $entity]);

@unlink($journalFile);
echo "\nleftover: tickets=" . $count('glpi_tickets', ['entities_id' => $entity]) . " maps=" . $count('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid]) . " documents=" . $count('glpi_documents', ['entities_id' => $entity]) . " limit=" . $origLimit . "\n";
echo "\n" . ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ($pass passed, $fail failed)\n";
if ($fail) { echo "--- log tail ---\n" . implode("\n", array_slice($log, -10)) . "\n"; }
