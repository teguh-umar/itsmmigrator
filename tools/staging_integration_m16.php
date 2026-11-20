<?php
// M16 integration check: GLPI-refused attachments (size limit) and the retry-skipped-attachments pass through the real Engine, TicketWriter and GLPI core.
// STAGING ONLY. A SYNTHETIC Freshservice (fake HTTP transport): 1 ticket with one 3 MB attachment. Its own entity and source; sets document_max_size itself and restores it;
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
$journalFile = sys_get_temp_dir() . '/itsm_m16_ids.json';
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



const TID = 8_700_000_001;
global $CFG_GLPI;
$origLimit = (int) $CFG_GLPI['document_max_size'];
$setLimit = function (int $mb) use ($DB) { global $CFG_GLPI; $CFG_GLPI['document_max_size'] = $mb; Config::setConfigurationValues('core', ['document_max_size' => $mb]); };
$journal['limit'] = $origLimit; $save();
$setLimit(2);
$requests = [];
$transport = function (string $url, array $h, ?string $sink) use (&$requests): array {
    if (str_contains($url, '/ticket_form_fields')) { return ['status' => 200, 'headers' => [], 'body' => '{"ticket_fields":[]}']; }
    if (str_contains($url, 'files.example.test')) { file_put_contents($sink, str_repeat('a', 3 * 1048576)); return ['status' => 200, 'headers' => [], 'body' => '']; }
    $requests[] = $url;
    if (preg_match('#/tickets/' . TID . '(\?|$)#', $url)) {
        return ['status' => 200, 'headers' => [], 'body' => json_encode(['ticket' => ['id' => TID, 'attachments' => [['id' => 8_700_000_301, 'name' => 'big-file.zip', 'content_type' => 'application/zip', 'size' => 3 * 1048576, 'attachment_url' => 'https://files.example.test/x']]]])];
    }
    if (preg_match('#/tickets/' . TID . '/#', $url)) { return ['status' => 200, 'headers' => [], 'body' => '{"conversations":[],"tasks":[],"time_entries":[]}']; }
    if (preg_match('#/api/v2/tickets\?#', $url)) {
        return ['status' => 200, 'headers' => [], 'body' => json_encode(['tickets' => [['id' => TID, 'subject' => 's', 'description' => '<p>d</p>', 'status' => 2, 'priority' => 2, 'type' => 'Incident', 'created_at' => '2026-03-01T00:00:00Z', 'updated_at' => '2026-03-02T00:00:00Z']]])];
    }
    return ['status' => 200, 'headers' => [], 'body' => '{}'];
};
$mkAdapter = fn () => new FreshserviceAdapter(new FreshserviceClient('https://fs.example.test', 'k', $transport, fn (float $s) => null, 0.0), 1);
AdapterFactory::setOverride(fn (array $s, string $k) => $mkAdapter());
$log = []; $logger = function (string $m) use (&$log) { $log[] = $m; };
$tid = fn () => (int) ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]])->current()['id'] ?? 0);
$notes = fn (string $like) => $count('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $tid(), 'is_private' => 1, ['content' => ['LIKE', $like]]]);
$docs = fn () => $count('glpi_documents_items', ['itemtype' => 'Ticket', 'items_id' => $tid()]);
$docMap = fn (string $st) => $count('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid, 'src_type' => 'document', 'status' => $st]);

// ---- 1. limit 2 MB, file 3 MB: the ticket is created, the attachment is skipped with ONE private note
$res = (new Engine($mkAdapter()))->run($fresh(), 'cli', ['tickets'], [], $logger);
chk('first run: ticket created, attachment skipped (no failed ticket), one private note, no document', $res['status'] === 'success' && $tickets() === 1 && $docMap('skipped') === 1 && $notes('%Attachment not imported:%') === 1 && $docs() === 0 && ($res['details']['attachments']['skipped_refused_by_glpi'] ?? 0) === 1, json_encode($res['details'] ?? []));

// ---- 2. the retry flag while the limit is still 2 MB: still refused, NO second note
$requests = [];
$res = (new Engine($mkAdapter()))->run($fresh(), 'cli', ['tickets'], ['retry_attachments' => true], $logger);
chk('retry with the same limit: still skipped, the one note stays, no second note, counted', $docMap('skipped') === 1 && $notes('%Attachment not imported:%') === 1 && $notes('%Attachment%') === 1 && ($res['details']['attachments']['still_refused'] ?? 0) === 1, json_encode($res['details'] ?? []));

// ---- 3. without the flag the skipped attachment is left alone even with a bigger limit
$setLimit(50);
(new Engine($mkAdapter()))->run($fresh(), 'cli', ['tickets'], [], $logger);
chk('no flag: nothing is fetched again, even with a bigger limit', $docMap('skipped') === 1 && $docs() === 0);

// ---- 4. the flag with the bigger limit: the file is in, linked, the note says "imported later"
$res = (new Engine($mkAdapter()))->run($fresh(), 'cli', ['tickets'], ['retry_attachments' => true], $logger);
chk('retry with the bigger limit: document created and linked to the ticket, map ok, no skipped left', $docs() === 1 && $docMap('ok') === 1 && $docMap('skipped') === 0 && ($res['details']['attachments']['imported_later'] ?? 0) === 1, json_encode($res['details'] ?? []));
chk('the earlier note became "imported later": still ONE note, no stale "not imported"', $notes('%Attachment imported later:%') === 1 && $notes('%Attachment not imported:%') === 0 && $notes('%Attachment%') === 1);

// ---- 5. again: nothing more happens
$requests = [];
(new Engine($mkAdapter()))->run($fresh(), 'cli', ['tickets'], ['retry_attachments' => true], $logger);
chk('a further retry run changes nothing (one document, one note, one request-free pass)', $docs() === 1 && $notes('%Attachment%') === 1 && count(array_filter($requests, fn ($u) => preg_match('#/tickets/' . TID . '(\?|$)#', $u))) === 0, 'reads=' . count($requests));

// ---- 6. a dry run writes nothing
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]]) as $r) { $DB->delete('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $r['id']]); $DB->delete('glpi_documents_items', ['itemtype' => 'Ticket', 'items_id' => $r['id']]); }
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_documents', 'WHERE' => ['entities_id' => $entity]]) as $d) { $DB->delete('glpi_documents', ['id' => $d['id']]); }
$wipe(); $setLimit(2);
(new Engine($mkAdapter()))->run($fresh(), 'cli', ['tickets'], [], $logger);
$setLimit(50);
$res = (new Engine($mkAdapter()))->run($fresh(), 'cli', ['tickets'], ['retry_attachments' => true, 'dry_run' => true], $logger);
chk('dry run with the flag: nothing written (still skipped, no document)', $docMap('skipped') === 1 && $docs() === 0);

// ---- cleanup (only what this tool made)
AdapterFactory::setOverride(null);
$setLimit($origLimit);
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]]) as $r) {
    $DB->delete('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $r['id']]);
    $DB->delete('glpi_documents_items', ['itemtype' => 'Ticket', 'items_id' => $r['id']]);
}
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_documents', 'WHERE' => ['entities_id' => $entity]]) as $d) { $DB->delete('glpi_documents', ['id' => $d['id']]); }
$wipe();
$DB->delete('glpi_plugin_itsmmigrator_runs', ['sources_id' => $sid]);
$DB->delete('glpi_plugin_itsmmigrator_sources', ['id' => $sid]);
$DB->delete('glpi_entities', ['id' => $entity]);
@unlink($journalFile);
echo "\nleftover: tickets=" . $count('glpi_tickets', ['entities_id' => $entity]) . " maps=" . $count('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid]) . " documents=" . $count('glpi_documents', ['entities_id' => $entity]) . " limit=" . $origLimit . "\n";
echo "\n" . ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ($pass passed, $fail failed)\n";
if ($fail) { echo "--- log tail ---\n" . implode("\n", array_slice($log, -10)) . "\n"; }
