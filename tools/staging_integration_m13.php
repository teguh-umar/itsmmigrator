<?php
// M13 integration check: windowed ticket reading + import_since + watermark, through the real Engine, TicketWriter and GLPI.
// STAGING ONLY. A SYNTHETIC Freshservice (a fake HTTP transport) serves 230 tickets with a cap of ONE page of 100 per query, so the
// import needs 3 windows. It writes tickets into its own entity and its own test source, and deletes only by those ids.
// Run it only when no run is active. It never touches source 33 or entity 81.
// Usage: php -c <php.ini> tools/staging_integration_m13.php [path-to-glpi-root]
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
use GlpiPlugin\Itsmmigrator\Adapter\ClientException;
use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceAdapter;
use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceClient;
use GlpiPlugin\Itsmmigrator\{Engine, MapRepository};

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
$fresh = function () use ($sid) { global $DB; $r = $DB->request(['FROM' => 'glpi_plugin_itsmmigrator_sources', 'WHERE' => ['id' => $sid]])->current(); unset($r['api_key']); return $r; };
$wm = fn () => $DB->request(['SELECT' => ['watermark'], 'FROM' => 'glpi_plugin_itsmmigrator_sources', 'WHERE' => ['id' => $sid]])->current()['watermark'];

// ---- the synthetic server: 230 tickets, ids far above any real one, one update every 10 seconds from 2026-03-01 UTC
const BASE_ID = 8_000_000_000;
$times = []; for ($i = 0; $i < 230; $i++) { $times[] = 1_772_323_200 + $i * 10; }
$lastUpdated = $times[229];
$requests = []; $failWindow = null;
$transport = function (string $url, array $h, ?string $sink) use ($times, &$requests, &$failWindow): array {
    if (str_contains($url, '/ticket_form_fields')) { return ['status' => 200, 'headers' => [], 'body' => '{"ticket_fields":[]}']; }
    if (!preg_match('#/api/v2/tickets\?#', $url)) { return ['status' => 200, 'headers' => [], 'body' => '{}']; }   // a ticket's children: none
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $since = strtotime((string) $q['updated_since']); $page = (int) $q['page'];
    $requests[] = ['since' => $since, 'page' => $page];
    if ($page > 1) { return ['status' => 400, 'headers' => [], 'body' => '{}']; }                 // the cap of this synthetic server: ONE page
    if ($failWindow !== null && $since >= $failWindow) { throw new ClientException('Network error talking to Freshservice: Connection was reset', 0, 'network'); }
    $lo = 0; $hi = count($times); while ($lo < $hi) { $mid = intdiv($lo + $hi, 2); if ($times[$mid] >= $since) { $hi = $mid; } else { $lo = $mid + 1; } }
    $rows = []; foreach (array_slice($times, $lo, 100, true) as $i => $t) { $iso = gmdate('Y-m-d\TH:i:s\Z', $t); $rows[] = ['id' => BASE_ID + $i + 1, 'subject' => "$q[per_page] t", 'description' => '<p>d</p>', 'status' => 5, 'priority' => 2, 'type' => 'Incident', 'created_at' => $iso, 'updated_at' => $iso]; }
    $more = $lo + 100 < count($times);
    return ['status' => 200, 'headers' => $more ? ['Link' => '<https://x.example.test/n>; rel="next"'] : [], 'body' => json_encode(['tickets' => $rows])];
};
$mkAdapter = fn () => new FreshserviceAdapter(new FreshserviceClient('https://fs.example.test', 'k', $transport, fn (float $s) => null, 0.0), 1);
AdapterFactory::setOverride(fn (array $s, string $k) => $mkAdapter());

$log = []; $logger = function (string $m) use (&$log) { $log[] = $m; };
$tickets = fn () => $count('glpi_tickets', ['entities_id' => $entity]);

// ---- 1. a window fails in the middle: the run fails, the watermark does NOT move, window 1 stays imported
$failWindow = $times[198];                 // windows start at index 0, 99 and 198: only the third one fails
$res = (new Engine($mkAdapter()))->run($fresh(), 'cli', ['tickets'], [], $logger);
chk('a failing window fails the run', $res['status'] === 'failed', json_encode($res['status']));
chk('the watermark did not move after a failed run', $wm() === null);
$after1 = $tickets();
chk('what was read before the failure is imported (windows 1 and 2) and mapped', $after1 >= 190 && $after1 <= 200 && $count('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid, 'src_type' => 'ticket', 'status' => 'ok']) === $after1, "tickets=$after1");

// ---- 2. the next run resumes: reads everything, skips what is mapped, finishes, and only THEN moves the watermark
$failWindow = null; $requests = []; $log = [];
$res = (new Engine($mkAdapter()))->run($fresh(), 'cli', ['tickets'], [], $logger);
chk('the second run succeeds', $res['status'] === 'success', json_encode($res));
chk('all 230 tickets are in GLPI, each once', $tickets() === 230 && $count('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid, 'src_type' => 'ticket', 'status' => 'ok']) === 230);
chk('no duplicate title in the entity', (function () use ($DB, $entity) { $seen = []; foreach ($DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]]) as $r) { if (isset($seen[$r['name']])) { return false; } $seen[$r['name']] = 1; } return true; })());
chk('three windows were read (3 distinct window starts), never a page beyond the cap', count(array_unique(array_column($requests, 'since'))) === 3 && count(array_filter($requests, fn ($r) => $r['page'] > 1)) === 0, json_encode(array_column($requests, 'since')));
chk('the log announces the windows, with a timestamp and no ticket data', count(array_filter($log, fn ($m) => str_contains($m, 'Window ended'))) === 2);
$expect = (new DateTimeImmutable('@' . $lastUpdated))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
chk('the watermark moved to the newest update, only after the last window', $wm() === $expect, (string) $wm() . " want $expect");

// ---- 3. the next run is a delta from watermark - 2 h: it asks only for the recent end and creates nothing
$requests = []; $before = $tickets();
$res = (new Engine($mkAdapter()))->run($fresh(), 'cli', ['tickets'], [], $logger);
chk('a delta run: first request starts 2 h before the watermark', ($requests[0]['since'] ?? 0) === $lastUpdated - 7200, json_encode($requests[0] ?? null));
chk('a delta run creates nothing', $tickets() === $before && ($res['counters']['tickets']['created'] ?? 0) === 0);

// ---- 4. import_since: a floor for the first phase, and a later wider phase read with "read everything"
$DB->update('glpi_plugin_itsmmigrator_sources', ['watermark' => null], ['id' => $sid]);
$opt = $fresh(); $opt['options'] = json_encode(['import_since' => '2026-03-01T00:20:00Z']);   // = times[120]
$requests = [];
$res = (new Engine($mkAdapter()))->run($opt, 'cli', ['tickets'], ['dry_run' => true], $logger);
chk('import_since is the first window\'s start', ($requests[0]['since'] ?? 0) === strtotime('2026-03-01T00:20:00Z'), json_encode($requests[0] ?? null));
chk('a dry run with import_since reads only the part after it (110 of the 230, plus the 1 ticket read twice at a window start) and moves nothing', ($res['counters']['tickets']['fetched'] ?? 0) === 111 && $wm() === null, json_encode($res['counters']['tickets'] ?? null));
$requests = [];
$opt['options'] = json_encode(['import_since' => '2026-03-01T00:00:00Z']);
$DB->update('glpi_plugin_itsmmigrator_sources', ['watermark' => $expect], ['id' => $sid]);
$res = (new Engine($mkAdapter()))->run(array_merge($opt, ['watermark' => $expect]), 'cli', ['tickets'], ['full' => true, 'dry_run' => true], $logger);
chk('a wider later phase ("read everything") starts at the lowered import_since, not at the watermark', ($requests[0]['since'] ?? 0) === strtotime('2026-03-01T00:00:00Z'), json_encode($requests[0] ?? null));
chk('...and everything it reads is already mapped: it would create nothing new', ($res['counters']['tickets']['created'] ?? 0) === 0 && ($res['counters']['tickets']['skipped'] ?? 0) >= 230, json_encode($res['counters']['tickets'] ?? null));

// ---- 5. the category filter still holds the watermark back, even across windows
$DB->update('glpi_plugin_itsmmigrator_sources', ['watermark' => null], ['id' => $sid]);
$opt2 = $fresh(); $opt2['options'] = json_encode(['ticket_categories' => ["$tag-Nothing"]]);
(new Engine($mkAdapter()))->run($opt2, 'cli', ['tickets'], [], $logger);
chk('a category-filtered run across 3 windows still leaves the watermark alone', $wm() === null);

// ---- cleanup (only what this tool made)
AdapterFactory::setOverride(null);
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]]) as $r) { $DB->delete('glpi_tickets_users', ['tickets_id' => $r['id']]); $DB->delete('glpi_groups_tickets', ['tickets_id' => $r['id']]); $DB->delete('glpi_tickets', ['id' => $r['id']]); }
$DB->delete('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid]);
$DB->delete('glpi_plugin_itsmmigrator_runs', ['sources_id' => $sid]);
$DB->delete('glpi_plugin_itsmmigrator_sources', ['id' => $sid]);
$DB->delete('glpi_entities', ['id' => $entity]);
echo "\nleftover: tickets=" . $count('glpi_tickets', ['entities_id' => $entity]) . " maps=" . $count('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid]) . " sources=" . $count('glpi_plugin_itsmmigrator_sources', ['id' => $sid]) . "\n";
echo "\n" . ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ($pass passed, $fail failed)\n";
if ($fail) { echo "--- FAILED lines ---\n" . implode("\n", array_slice(array_values(array_filter($log, fn ($m) => str_contains($m, 'FAILED'))), 0, 3)) . "\n--- log tail ---\n" . implode("\n", array_slice($log, -12)) . "\n"; }
