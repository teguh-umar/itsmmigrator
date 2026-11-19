<?php
// M14 integration check: the import RANGE (from / until, by last update or by creation day) through the real Engine, TicketWriter and GLPI.
// STAGING ONLY. A SYNTHETIC Freshservice (fake HTTP transport): 230 tickets, one every 6 hours from 2026-03-01 UTC, created 2 days before their
// last update, at most ONE page of 100 per query. Its own entity and test source; deletes only by those ids. Run it only when no run is active.
// It never touches source 33 or entity 81.
// Usage: php -c <php.ini> tools/staging_integration_m14.php [path-to-glpi-root]
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

$tag = 'itsmtest' . substr(md5((string) microtime(true)), 0, 6);
$entity = (new Entity())->add(['name' => "$tag-target", 'entities_id' => 0]);
$_SESSION['glpiactiveentities'] = [0, $entity];
$now = date('Y-m-d H:i:s');
$DB->insert('glpi_plugin_itsmmigrator_sources', ['name' => "$tag-src", 'adapter' => 'freshservice', 'base_url' => 'https://fs.example.test', 'api_key' => (new GLPIKey())->encrypt('k'),
    'entities_id' => $entity, 'schedule' => 'manual', 'is_active' => 1, 'date_creation' => $now, 'date_mod' => $now]);
$sid = (int) $DB->insertId();
$fresh = function (array $options = []) use ($sid) { global $DB; $r = $DB->request(['FROM' => 'glpi_plugin_itsmmigrator_sources', 'WHERE' => ['id' => $sid]])->current(); unset($r['api_key']); $r['options'] = $options === [] ? null : json_encode($options); return $r; };
$wm = fn () => $DB->request(['SELECT' => ['watermark'], 'FROM' => 'glpi_plugin_itsmmigrator_sources', 'WHERE' => ['id' => $sid]])->current()['watermark'];
$tickets = fn () => $count('glpi_tickets', ['entities_id' => $entity]);
$wipe = function () use ($DB, $entity, $sid) {
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]]) as $r) { $DB->delete('glpi_tickets_users', ['tickets_id' => $r['id']]); $DB->delete('glpi_groups_tickets', ['tickets_id' => $r['id']]); $DB->delete('glpi_tickets', ['id' => $r['id']]); }
    $DB->delete('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid]);
    $DB->update('glpi_plugin_itsmmigrator_sources', ['watermark' => null], ['id' => $sid]);
};

const BASE_ID = 8_500_000_000;
$t0 = gmmktime(0, 0, 0, 3, 1, 2026);
$times = []; for ($i = 0; $i < 230; $i++) { $times[] = $t0 + $i * 6 * 3600; }
$requests = [];
$transport = function (string $url, array $h, ?string $sink) use ($times, &$requests): array {
    if (str_contains($url, '/ticket_form_fields')) { return ['status' => 200, 'headers' => [], 'body' => '{"ticket_fields":[]}']; }
    if (!preg_match('#/api/v2/tickets\?#', $url)) { return ['status' => 200, 'headers' => [], 'body' => '{}']; }
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $since = strtotime((string) $q['updated_since']); $page = (int) $q['page'];
    $requests[] = ['since' => $since, 'page' => $page];
    if ($page > 1) { return ['status' => 400, 'headers' => [], 'body' => '{}']; }
    $lo = 0; $hi = count($times); while ($lo < $hi) { $mid = intdiv($lo + $hi, 2); if ($times[$mid] >= $since) { $hi = $mid; } else { $lo = $mid + 1; } }
    $rows = [];
    foreach (array_slice($times, $lo, 100, true) as $i => $t) { $rows[] = ['id' => BASE_ID + $i + 1, 'subject' => 's', 'description' => '<p>d</p>', 'status' => 5, 'priority' => 2, 'type' => 'Incident', 'created_at' => gmdate('Y-m-d\TH:i:s\Z', $t - 2 * 86400), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $t)]; }
    return ['status' => 200, 'headers' => ($lo + 100 < count($times)) ? ['Link' => '<https://x.example.test/n>; rel="next"'] : [], 'body' => json_encode(['tickets' => $rows])];
};
$mkAdapter = fn () => new FreshserviceAdapter(new FreshserviceClient('https://fs.example.test', 'k', $transport, fn (float $s) => null, 0.0), 1);
AdapterFactory::setOverride(fn (array $s, string $k) => $mkAdapter());
$log = []; $logger = function (string $m) use (&$log) { $log[] = $m; };
$ids = fn () => array_map(fn ($r) => $r['src_id'], iterator_to_array($DB->request(['SELECT' => ['src_id'], 'FROM' => 'glpi_plugin_itsmmigrator_maps', 'WHERE' => ['sources_id' => $sid, 'src_type' => 'ticket'], 'ORDER' => 'src_id']), false));

// ---- 1. an END date by last update: from 2026-03-01 until 2026-03-10 -> updated <= 03-10 23:59:59 -> tickets 0..39
$wipe(); $requests = []; $log = [];
$res = (new Engine($mkAdapter()))->run($fresh(['import_since' => '2026-03-01', 'import_until' => '2026-03-10']), 'cli', ['tickets'], [], $logger);
chk('until (by update): run succeeds', $res['status'] === 'success', json_encode($res['status']));
chk('until (by update): exactly the 40 tickets updated up to the END of 2026-03-10 are imported', $tickets() === 40 && count($ids()) === 40 && in_array((string) (BASE_ID + 40), $ids(), true) && !in_array((string) (BASE_ID + 41), $ids(), true), 'tickets=' . $tickets());
chk('until (by update): the reading STOPPED at the end date (one request, no further windows)', count($requests) === 1, json_encode(array_column($requests, 'since')));
chk('until: the log says the end date was reached', count(array_filter($log, fn ($m) => str_contains($m, 'end date was reached'))) === 1);
chk('until: a range-limited run leaves the watermark alone', $wm() === null);

// ---- 2. the same range given as flags (the Runs page / CLI): flags win over the options
$wipe(); $requests = [];
$res = (new Engine($mkAdapter()))->run($fresh(['import_until' => '2026-03-31']), 'cli', ['tickets'], ['since' => '2026-03-01', 'until' => '2026-03-05'], $logger);
chk('flags win over the options: until 2026-03-05 gives the 20 tickets updated up to its end', $tickets() === 20, 'tickets=' . $tickets());
chk('empty form fields do not hide the options', (function () use ($mkAdapter, $fresh, $logger, $wipe, $tickets) { $wipe(); (new Engine($mkAdapter()))->run($fresh(['import_until' => '2026-03-05']), 'cli', ['tickets'], ['since' => '2026-03-01', 'until' => '', 'date_basis' => ''], $logger); return $tickets() === 20; })());

// ---- 3. by CREATION day: created from 2026-03-05 to 2026-03-10 (created = updated - 2 days) -> tickets 24..47
$wipe(); $requests = []; $log = [];
$res = (new Engine($mkAdapter()))->run($fresh(['import_since' => '2026-03-05', 'import_until' => '2026-03-10', 'import_date_basis' => 'created']), 'cli', ['tickets'], [], $logger);
chk('by creation day: exactly the 24 tickets CREATED in 2026-03-05..2026-03-10 are imported', $tickets() === 24 && min($ids()) === (string) (BASE_ID + 25) && max($ids()) === (string) (BASE_ID + 48), 'tickets=' . $tickets() . ' ' . json_encode([min($ids() ?: ['-']), max($ids() ?: ['-'])]));
chk('by creation day: the reader did NOT stop at the first ticket past the end (a late update can belong to an early ticket)', count(array_filter($log, fn ($m) => str_contains($m, 'end date was reached'))) === 0);
chk('by creation day: the first request starts at the start date (not at a watermark)', ($requests[0]['since'] ?? 0) === gmmktime(0, 0, 0, 3, 5, 2026), json_encode($requests[0] ?? null));
chk('by creation day: the watermark stays put', $wm() === null);
chk('by creation day: tickets outside the range are counted as skipped, not failed', ($res['counters']['tickets']['failed'] ?? 0) === 0 && ($res['counters']['tickets']['skipped'] ?? 0) > 0, json_encode($res['counters']['tickets'] ?? null));

// ---- 4. a delta after a creation-day run does not use the watermark either (each run re-reads its range)
$requests = [];
(new Engine($mkAdapter()))->run($fresh(['import_since' => '2026-03-05', 'import_until' => '2026-03-10', 'import_date_basis' => 'created']), 'cli', ['tickets'], [], $logger);
chk('a second creation-day run creates nothing new and starts at the start date again', $tickets() === 24 && ($requests[0]['since'] ?? 0) === gmmktime(0, 0, 0, 3, 5, 2026));

// ---- 5. phases still work with a normal (update) reading: no end date -> the watermark moves as before
$wipe(); $requests = [];
$res = (new Engine($mkAdapter()))->run($fresh(['import_since' => '2026-03-01']), 'cli', ['tickets'], [], $logger);
chk('no end date: the whole list is imported across windows (230) and the watermark moves', $tickets() === 230 && $wm() === (new DateTimeImmutable('@' . $times[229]))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'), 'tickets=' . $tickets());

// ---- 6. a range that matches nothing is not an error
$wipe();
$res = (new Engine($mkAdapter()))->run($fresh(['import_since' => '2027-01-01', 'import_until' => '2027-01-31']), 'cli', ['tickets'], [], $logger);
chk('an empty range: success, nothing imported', $res['status'] === 'success' && $tickets() === 0);

// ---- cleanup (only what this tool made)
AdapterFactory::setOverride(null);
$wipe();
$DB->delete('glpi_plugin_itsmmigrator_runs', ['sources_id' => $sid]);
$DB->delete('glpi_plugin_itsmmigrator_sources', ['id' => $sid]);
$DB->delete('glpi_entities', ['id' => $entity]);
echo "\nleftover: tickets=" . $count('glpi_tickets', ['entities_id' => $entity]) . " maps=" . $count('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid]) . " sources=" . $count('glpi_plugin_itsmmigrator_sources', ['id' => $sid]) . "\n";
echo "\n" . ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ($pass passed, $fail failed)\n";
if ($fail) { echo "--- log tail ---\n" . implode("\n", array_slice($log, -10)) . "\n"; }
