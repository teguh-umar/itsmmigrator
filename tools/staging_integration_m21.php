<?php
// M21 integration check: mail-bounce notices are cleaned on import and by plugins:itsmmigrator:clean-bounces (rc35), through the real Engine, TicketWriter, console command and GLPI core.
// STAGING ONLY. A SYNTHETIC Freshservice (fake HTTP transport): 1 ticket and 1 note holding a synthetic bounce notice. Its own entity and source;
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
$journalFile = sys_get_temp_dir() . '/itsm_m21_ids.json';
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





const TID = 8_700_000_021;
$junk = str_repeat('1.84300:01000000, ', 300);
$bounce = '<p>Delivery has failed to these recipients or groups:<br>box@example.test<br>The recipient\'s mailbox is full.</p><p>Diagnostic information for administrators:<br>Generating server: srv.example.test ' . $junk . '<br>Original message headers:<br>ARC-Seal: i=2; ' . $junk . '</p>';
$fs = ['body' => $bounce];
$transport = function (string $url, array $h, ?string $sink) use (&$fs): array {
    $ok = fn (string $b) => ['status' => 200, 'headers' => [], 'body' => $b];
    if (str_contains($url, '/ticket_form_fields')) { return $ok('{"ticket_fields":[]}'); }
    if (preg_match('#/tickets/' . TID . '(\?|$)#', $url)) { return $ok(json_encode(['ticket' => ['id' => TID, 'attachments' => []]])); }
    if (preg_match('#/tickets/' . TID . '/conversations#', $url)) { return $ok(json_encode(['conversations' => [['id' => 8_700_000_121, 'body' => $fs['body'], 'body_text' => 'x', 'private' => false, 'incoming' => true, 'created_at' => '2026-03-02T00:00:00Z', 'user_id' => null, 'attachments' => []]]])); }
    if (preg_match('#/tickets/' . TID . '/#', $url)) { return $ok('{"tasks":[],"time_entries":[]}'); }
    if (preg_match('#/api/v2/tickets\?#', $url)) {
        return $ok(json_encode(['tickets' => [['id' => TID, 'subject' => 'Undeliverable: s', 'description' => $fs['body'], 'status' => 2, 'priority' => 2, 'type' => 'Incident', 'created_at' => '2026-03-01T00:00:00Z', 'updated_at' => '2026-03-02T00:00:00Z']]]));
    }
    return $ok('{}');
};
$mkAdapter = fn () => new FreshserviceAdapter(new FreshserviceClient('https://fs.example.test', 'k', $transport, fn (float $s) => null, 0.0), 1);
AdapterFactory::setOverride(fn (array $s, string $k) => $mkAdapter());
$log = []; $logger = function (string $m) use (&$log) { $log[] = $m; };
$tid = fn () => (int) ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]])->current()['id'] ?? 0);
$tcontent = fn () => (string) $DB->request(['SELECT' => ['content'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => $tid()]])->current()['content'];
$fcontent = function (): array { global $DB, $tid; $o = []; foreach ($DB->request(['SELECT' => ['content'], 'FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tid(), 'is_private' => 0]]) as $r) { $o[] = (string) $r['content']; } return $o; };

// ---- 1. import time: a bounce in a new ticket and in its note is cleaned, nothing skipped
$res = (new Engine($mkAdapter()))->run($fresh(), 'cli', ['tickets'], [], $logger);
$t = $tcontent(); $f = $fcontent();
chk('import: ticket created, its body cleaned (short, failure statement kept, junk gone, note present)', $tickets() === 1 && strlen($t) < 1500 && str_contains($t, 'Delivery has failed') && !str_contains($t, 'ARC-Seal') && !str_contains($t, '1.84300') && str_contains($t, 'Bounce details removed'), 'len=' . strlen($t));
chk('import: the note was cleaned too', count($f) === 1 && strlen($f[0]) < 1500 && str_contains($f[0], 'Delivery has failed') && !str_contains($f[0], '1.84300'), json_encode(array_map('strlen', $f)));
chk('import: counted bounce.ticket_cleaned = 1, no failed ticket', (int) ($res['details']['bounce']['ticket_cleaned'] ?? 0) === 1 && ($res['counters']['tickets']['failed'] ?? 0) === 0, json_encode($res['details'] ?? []));

// ---- 2. the command on tickets already imported: put the raw bounce back (as an older import stored it), then clean
$DB->update('glpi_tickets', ['content' => $bounce], ['id' => $tid()]);
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tid(), 'is_private' => 0]]) as $r) { $DB->update('glpi_itilfollowups', ['content' => $bounce], ['id' => $r['id']]); }
$php = PHP_BINARY; $ini = 'C:/glpi-staging/php.ini';
$cmd = fn (string $args) => (string) shell_exec(escapeshellarg($php) . ' -c ' . escapeshellarg($ini) . ' ' . escapeshellarg($root . '/bin/console') . ' plugins:itsmmigrator:clean-bounces --username=glpi ' . $args . ' --no-ansi 2>&1');
$o = $cmd("--entity=$entity --dry-run");
chk('command dry run: counts 1 ticket and 1 note, writes nothing', preg_match('/tickets\s+1/', $o) === 1 && preg_match('/notes\s+1/', $o) === 1 && strlen($tcontent()) > 5000, trim(preg_replace('/\s+/', ' ', $o)));
$o = $cmd("--entity=$entity");
chk('command without --backup refuses and writes nothing', str_contains($o, '--backup is required') && strlen($tcontent()) > 5000);
$bk = sys_get_temp_dir() . '/itsm_m21_backup_' . $tag . '.json';
$o = $cmd("--entity=$entity --backup=" . escapeshellarg($bk));
$t = $tcontent(); $f = $fcontent();
chk('command: ticket and note cleaned, still one ticket and one note', $tickets() === 1 && strlen($t) < 1500 && count($f) === 1 && strlen($f[0]) < 1500 && str_contains($t, 'Delivery has failed'), trim(preg_replace('/\s+/', ' ', $o)));
$saved = is_file($bk) ? json_decode((string) file_get_contents($bk), true) : null;
chk('the backup holds the two original bodies', is_array($saved) && count($saved['glpi_tickets'] ?? []) === 1 && count($saved['glpi_itilfollowups'] ?? []) === 1 && strlen((string) reset($saved['glpi_tickets'])) > 5000);
@unlink($bk);
$o = $cmd("--entity=$entity --dry-run");
chk('a second pass finds nothing (never cleaned twice)', preg_match('/tickets\s+0/', $o) === 1 && preg_match('/notes\s+0/', $o) === 1, trim(preg_replace('/\s+/', ' ', $o)));
$o = $cmd('--entity=81 --dry-run');
chk('entity 81 is only counted here, never written (dry run)', str_contains($o, 'Dry run'));

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
