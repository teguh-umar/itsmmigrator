<?php
// M14 integration check: the import RANGE (from / until, by last update or by creation day) through the real Engine, TicketWriter and GLPI.
// STAGING ONLY. A SYNTHETIC Freshservice (fake HTTP transport): 1 ticket with 4 approvals. Its own entity, source and two test users;
// deletes only by those ids. Run it only when no run is active.
// It never touches source 33 or entity 81.
// Usage: php -c <php.ini> tools/staging_integration_m15.php [path-to-glpi-root]
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
$journalFile = sys_get_temp_dir() . '/itsm_m15_ids.json';
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


const TID = 8_700_000_001; const AP1 = 8_700_000_101; const AP2 = 8_700_000_102;
$u1 = (new User())->add(['name' => "$tag-approver1", 'authtype' => 1]);
$u2 = (new User())->add(['name' => "$tag-approver2", 'authtype' => 1]);
$journal['users'] = [$u1, $u2]; $journal['maps'] = ['requester:' . AP1, 'requester:' . AP2]; $save();
$mapUsers = [];
foreach ([['requester:' . AP1, $u1], ['requester:' . AP2, $u2]] as [$srcUser, $uid]) {
    $DB->insert('glpi_plugin_itsmmigrator_maps', ['sources_id' => 0, 'source' => 'freshservice', 'src_type' => 'user', 'src_id' => $srcUser, 'itemtype' => 'User', 'items_id' => $uid, 'action' => 'created', 'status' => 'ok', 'date_creation' => $now, 'date_mod' => $now]);
    $mapUsers[] = $srcUser;
}
$requests = [];
$transport = function (string $url, array $h, ?string $sink) use (&$requests): array {
    if (str_contains($url, '/ticket_form_fields')) { return ['status' => 200, 'headers' => [], 'body' => '{"ticket_fields":[]}']; }
    $requests[] = $url;
    if (preg_match('#/requesters/8700999998$#', $url)) { return ['status' => 200, 'headers' => [], 'body' => json_encode(['requester' => ['id' => 8700999998, 'first_name' => 'Nobody', 'last_name' => 'Matched', 'primary_email' => 'nobody@example.test']])]; }
    if (preg_match('#/tickets/' . TID . '/approvals#', $url)) {
        return ['status' => 200, 'headers' => [], 'body' => json_encode(['approvals' => [
            ['id' => 8_700_000_201, 'approval_status' => 'Approved', 'approver_id' => AP1, 'user_id' => AP2, 'level' => 1, 'latest_remark' => 'ok by one', 'created_at' => '2026-03-01T01:00:00Z', 'updated_at' => '2026-03-01T05:00:00Z'],
            ['id' => 8_700_000_202, 'approval_status' => 'Rejected', 'approver_id' => AP2, 'level' => 2, 'latest_remark' => 'no by two', 'created_at' => '2026-03-01T06:00:00Z', 'updated_at' => '2026-03-02T00:00:00Z'],
            ['id' => 8_700_000_203, 'approval_status' => 'Requested', 'approver_id' => AP1, 'level' => 3, 'created_at' => '2026-03-02T01:00:00Z', 'updated_at' => '2026-03-02T01:00:00Z'],
            ['id' => 8_700_000_204, 'approval_status' => 'Approved', 'approver_id' => 8_700_999_999, 'level' => 2, 'created_at' => '2026-03-02T01:00:00Z', 'updated_at' => '2026-03-02T02:00:00Z'],
        ]])];
    }
    if (preg_match('#/tickets/' . TID . '(\?|$)#', $url)) { return ['status' => 200, 'headers' => [], 'body' => '{"ticket":{"id":' . TID . '}}']; }
    if (preg_match('#/tickets/' . TID . '/#', $url)) { return ['status' => 200, 'headers' => [], 'body' => '{"conversations":[],"tasks":[],"time_entries":[]}']; }
    if (preg_match('#/api/v2/tickets\?#', $url)) {
        return ['status' => 200, 'headers' => [], 'body' => json_encode(['tickets' => [['id' => TID, 'subject' => 's', 'description' => '<p>d</p>', 'status' => 2, 'priority' => 2, 'type' => 'Incident', 'requester_id' => 8_700_999_998, 'created_at' => '2026-03-01T00:00:00Z', 'updated_at' => '2026-03-02T00:00:00Z']]])];
    }
    return ['status' => 200, 'headers' => [], 'body' => '{}'];
};
$mkAdapter = fn () => new FreshserviceAdapter(new FreshserviceClient('https://fs.example.test', 'k', $transport, fn (float $s) => null, 0.0), 1);
AdapterFactory::setOverride(fn (array $s, string $k) => $mkAdapter());
$log = []; $logger = function (string $m) use (&$log) { $log[] = $m; };
$vals = function () use ($DB, $entity) {
    $tid = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]])->current()['id'] ?? 0;
    return iterator_to_array($DB->request(['FROM' => 'glpi_ticketvalidations', 'WHERE' => ['tickets_id' => $tid], 'ORDER' => 'id']), false);
};
$stepName = function (int $id) use ($DB) {
    $r = $DB->request(['SELECT' => ['vs.name AS n'], 'FROM' => 'glpi_itils_validationsteps AS ivs', 'INNER JOIN' => ['glpi_validationsteps AS vs' => ['ON' => ['ivs' => 'validationsteps_id', 'vs' => 'id']]], 'WHERE' => ['ivs.id' => $id]])->current();
    return $r['n'] ?? null;
};
$approvalMaps = fn () => $count('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid, 'src_type' => 'approval']);
$asks = function () use (&$requests) { return count(array_filter($requests, fn ($u) => str_contains($u, '/approvals'))); };

// ---- 1. without the option: no approval request, no validation
$res = (new Engine($mkAdapter()))->run($fresh(), 'cli', ['tickets'], [], $logger);
chk('off by default: ticket imported, no approvals request, no validation rows', $res['status'] === 'success' && $tickets() === 1 && $vals() === [] && $asks() === 0, json_encode($res['status']));

// ---- 2. with the source option
$wipe(); $requests = []; $log = [];
$res = (new Engine($mkAdapter()))->run($fresh(['import_approvals' => true]), 'cli', ['tickets'], [], $logger);
$v = $vals();
chk('option: run succeeds and asks for the approvals once', $res['status'] === 'success' && $asks() === 1, json_encode($res['status']) . ' asks=' . $asks());
chk('3 approvals imported, the one with an unknown approver skipped and counted', count($v) === 3 && $approvalMaps() === 3 && ($res['details']['approvals']['skipped_approver_unmapped'] ?? 0) === 1, 'rows=' . count($v) . ' ' . json_encode($res['counters']));
chk('statuses: approved 3, refused 4, waiting 2', array_map(fn ($r) => (int) $r['status'], $v) === [3, 4, 2], json_encode(array_column($v, 'status')));
chk('approvers are the right GLPI users', array_map(fn ($r) => (int) $r['items_id_target'], $v) === [$u1, $u2, $u1] && array_unique(array_column($v, 'itemtype_target')) === ['User'], json_encode(array_column($v, 'items_id_target')));
$names = array_map(fn ($r) => $stepName((int) $r['itils_validationsteps_id']), $v);
chk('one approval step per level, named Level N', $names === ['Level 1', 'Level 2', 'Level 3'], json_encode($names));
$sub = (new DateTimeImmutable('2026-03-01T01:00:00Z'))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
chk('requester, dates and remarks carried over', (int) ($v[0]['users_id'] ?? 0) === $u2 && ($v[0]['comment_validation'] ?? '') === 'ok by one' && ($v[1]['comment_validation'] ?? '') === 'no by two' && ($v[0]['validation_date'] ?? null) !== null && array_key_exists('validation_date', $v[2] ?? []) && $v[2]['validation_date'] === null && ($v[0]['submission_date'] ?? '') === $sub, json_encode(array_map(fn ($r) => [$r['users_id'], $r['comment_validation'], $r['validation_date'], $r['submission_date']], $v)) . ' u2=' . $u2);
chk('the log names no test user (ids and counts only)', array_filter($log, fn ($m) => str_contains($m, '-approver')) === []);

// ---- 2b. the requester is not a GLPI user: the ticket has no requester, but its first line says who asked
$row = $DB->request(['SELECT' => ['id', 'content'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]])->current();
$plain = html_entity_decode(strip_tags(\Glpi\RichText\RichText::getSafeHtml((string) ($row['content'] ?? ''))));
chk('unmatched requester: the first line of the ticket names who asked (name and e-mail from the source)', str_starts_with(ltrim($plain), 'Requester (source): Nobody Matched <nobody@example.test>'), substr($plain, 0, 90));
chk('unmatched requester: no requester actor, the description follows the line', $count('glpi_tickets_users', ['tickets_id' => $row['id'], 'type' => 1]) === 0 && str_contains($plain, 'd'));

// ---- 3. a second run adds nothing
(new Engine($mkAdapter()))->run($fresh(['import_approvals' => true]), 'cli', ['tickets'], ['full' => true], $logger);
chk('second run (read everything): still 3 validations, 3 approval maps', count($vals()) === 3 && $approvalMaps() === 3, count($vals()) . '/' . $approvalMaps());

// ---- 4. the run flag turns it on without the option
$wipe(); $requests = [];
(new Engine($mkAdapter()))->run($fresh(), 'cli', ['tickets'], ['approvals' => true], $logger);
chk('flag approvals=true works without the source option', count($vals()) === 3);

// ---- 5. a dry run writes nothing and asks nothing
$wipe(); $requests = [];
(new Engine($mkAdapter()))->run($fresh(['import_approvals' => true]), 'cli', ['tickets'], ['dry_run' => true], $logger);
chk('dry run: no ticket, no approvals request', $tickets() === 0 && $asks() === 0);

// ---- cleanup (only what this tool made)
AdapterFactory::setOverride(null);
$purge($journal); // by the journalled ids, through core for users (and their profile rows)
@unlink($journalFile);
echo "\nleftover: tickets=" . $count('glpi_tickets', ['entities_id' => $entity]) . " maps=" . $count('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid]) . " sources=" . $count('glpi_plugin_itsmmigrator_sources', ['id' => $sid]) . " users=" . $count('glpi_users', ['id' => [$u1, $u2]]) . "\n";
echo "\n" . ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ($pass passed, $fail failed)\n";
if ($fail) { echo "--- log tail ---\n" . implode("\n", array_slice($log, -10)) . "\n"; }
