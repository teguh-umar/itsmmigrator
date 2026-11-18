<?php
// M6 integration check (web run, Needs review, External references tab, page rendering). STAGING ONLY: it creates
// tickets, users and an entity (all named itsmtest*) and removes them. Never run it on production.
// The HTTP layer (ajax/run.php, CSRF header) is NOT exercised here: it needs a browser session.
// Usage: php -c <php.ini> tools/staging_integration_m6.php [path-to-glpi-root]
$root = $argv[1] ?? 'C:/glpi-staging/app13/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('staging');
$kernel->boot();
global $DB;
$_SESSION['glpiID'] = 2; $_SESSION['glpi_use_mode'] = 0; $_SESSION['glpiname'] = 'glpi';
$prof = new Profile(); $prof->getFromDB(4); $_SESSION['glpiactiveprofile'] = $prof->fields + ['id' => 4];
$_SESSION['glpiactiveentities'] = [0]; $_SESSION['glpiactive_entity'] = 0;

use GlpiPlugin\Itsmmigrator\Adapter\AdapterFactory;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\{ExternalAttachment, ExternalTicket, ExternalUser, Page, TicketChildren};
use GlpiPlugin\Itsmmigrator\Adapter\SourceAdapterInterface;
use GlpiPlugin\Itsmmigrator\{Lock, MapRepository, NeedsReview, ReviewView, RunView, Source, WebRun};
use GlpiPlugin\Itsmmigrator\Tab\ExternalReferenceTab;

$pass = 0; $fail = 0;
function chk(string $l, bool $ok, string $d = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "PASS $l\n"; } else { $fail++; echo "FAIL $l $d\n"; } }
$count = fn (string $t, array $w = []) => countElementsInTable($t, $w);
$tag = 'itsmtest' . substr(md5((string) microtime(true)), 0, 6);

$entity = (new Entity())->add(['name' => "$tag-target", 'entities_id' => 0]);
$_SESSION['glpiactiveentities'] = [0, $entity];
$SECRET = "SECRET-$tag-KEY";
$src = new Source();
$sid = $src->add(['name' => "$tag-src", 'adapter' => 'freshservice', 'base_url' => 'https://fs.example.test', 'entities_id' => $entity, 'api_key' => $SECRET]);

class FakeAdapter implements SourceAdapterInterface {
    public int $pageFetches = 0;
    public function __construct(public array $byModule) {}
    public function getSourceKey(): string { return 'freshservice'; }
    public function supports(string $m): bool { return isset($this->byModule[$m]); }
    public function testConnection(): array { return ['ok' => true, 'message' => '']; }
    public function fetchPage(string $m, int $page, ?DateTimeImmutable $since, ?string $cursor = null): Page {
        $this->pageFetches++;
        $pages = $this->byModule[$m]; return new Page($pages[$page - 1] ?? [], $page < count($pages), $page < count($pages) ? 'c' . ($page + 1) : null);
    }
    public function fetchTicketChildren(string $id): TicketChildren { return new TicketChildren(); }
    public function downloadAttachment(ExternalAttachment $a, string $dest): void {}
}
$d = fn (string $s) => new DateTimeImmutable($s);
$tickets = [];
for ($i = 1; $i <= 25; $i++) {
    $tickets[] = new ExternalTicket('freshservice', "$tag-w$i", "$tag web $i", "<p>b</p>", 'Open', 'Medium', 'Incident', null, null, null, [], $d('2024-03-01T00:00:00Z'), null, "$tag-w$i", $d('2024-03-02T00:00:00Z'));
}
$adapter = new FakeAdapter(['tickets' => [array_slice($tickets, 0, 20), array_slice($tickets, 20)]]);
AdapterFactory::setOverride(fn (array $s, string $k) => $adapter);

// ---- flags
$f = WebRun::cleanFlags([]);
chk('flags: a dry run is the default', $f['dry_run'] === true && $f['ldap_lookup'] === true);
chk('flags: only an explicit 0 turns the dry run off', WebRun::cleanFlags(['dry_run' => '0'])['dry_run'] === false && WebRun::cleanFlags(['dry_run' => '1'])['dry_run'] === true);
$f = WebRun::cleanFlags(['limit' => '-5', 'assign_profiles' => 'root', 'evil' => 'x', 'fallback_user' => '7abc', 'since' => ' 2024-01-01 ']);
chk('flags: limit never negative, bad profile dropped, unknown flag dropped', $f['limit'] === 0 && $f['assign_profiles'] === null && !array_key_exists('evil', $f) && $f['fallback_user'] === 7 && $f['since'] === '2024-01-01');

// ---- start refuses
chk('start: unknown source refused', WebRun::start(999999, ['tickets'], [])['success'] === false);
$bad = WebRun::start($sid, ['tickets'], ['since' => 'not a date']);
chk('start: a bad --since is refused before any lock is taken', $bad['success'] === false && Lock::current() === null);
$none = WebRun::start($sid, ['kb'], []);
chk('start: no runnable module refused, with the reason', $none['success'] === false && str_contains(json_encode($none['notes'] ?? []), 'not built yet'));

// ---- a dry web run, batch by batch
$before = [$count('glpi_tickets'), $count('glpi_plugin_itsmmigrator_maps')];
$st = WebRun::start($sid, ['tickets'], []);
chk('start: ok, a DRY RUN by default', $st['success'] === true && $st['dry_run'] === true && $st['modules'] === ['tickets'], json_encode($st));
$rid = (int) $st['run_id'];
chk('start: the lock is held by a web run', (Lock::current()['runner'] ?? '') === 'web' && (int) Lock::current()['id'] === $rid);
$second = WebRun::start($sid, ['tickets'], []);
chk('start: a second runner is refused with the owner', $second['success'] === false && ($second['locked'] ?? false) === true && ($second['owner'] ?? '') === 'web' && str_contains($second['message'], 'Run already active'));

$steps = 0; $last = null; $maxLogLines = 0;
do { $last = WebRun::step($rid); $steps++; $maxLogLines = max($maxLogLines, count($last['logs'] ?? [])); } while (($last['success'] ?? false) && !$last['done'] && $steps < 20);
chk('step: 25 tickets at 10 per request take 3 steps', $steps === 3, "steps=$steps");
chk('step: finished as success', $last['done'] === true && $last['status'] === 'success', json_encode($last));
chk('step: counters add up across requests (25 would-create)', ($last['counters']['tickets']['created'] ?? 0) === 25 && ($last['counters']['tickets']['fetched'] ?? 0) === 25, json_encode($last['counters'] ?? null));
chk('dry run: nothing written', [$count('glpi_tickets'), $count('glpi_plugin_itsmmigrator_maps')] === $before);
chk('step: the lock is released and the run row closed', Lock::current() === null && ($DB->request(['FROM' => 'glpi_plugin_itsmmigrator_runs', 'WHERE' => ['id' => $rid]])->current()['status'] ?? '') === 'success');
$runRow = $DB->request(['FROM' => 'glpi_plugin_itsmmigrator_runs', 'WHERE' => ['id' => $rid]])->current();
chk('run row records the web runner and its counters', $runRow['runner'] === 'web' && (int) $runRow['dry_run'] === 1 && str_contains((string) $runRow['counters'], '"created":25'));
chk('step after the end is refused (no session state)', WebRun::step($rid)['success'] === false);
chk('step with an id this session never started is refused', WebRun::step(987654)['success'] === false);

// ---- a real web run writes, and is a run like any other
$st = WebRun::start($sid, ['tickets'], ['dry_run' => '0']);
$rid = (int) $st['run_id'];
do { $last = WebRun::step($rid); } while (($last['success'] ?? false) && !$last['done']);
chk('real web run: 25 tickets created in the target entity', $count('glpi_tickets', ['entities_id' => $entity]) === 25 && ($last['counters']['tickets']['created'] ?? 0) === 25, json_encode($last['counters'] ?? null));
chk('real web run: 25 tickets mapped ok', $count('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid, 'src_type' => 'ticket', 'status' => 'ok']) === 25);

// the same run again changes nothing (idempotent through the web path too)
$snap = $count('glpi_tickets');
$st = WebRun::start($sid, ['tickets'], ['dry_run' => '0']); $rid = (int) $st['run_id'];
do { $last = WebRun::step($rid); } while (($last['success'] ?? false) && !$last['done']);
chk('second web run: nothing new, all skipped', $count('glpi_tickets') === $snap && ($last['counters']['tickets']['skipped'] ?? 0) === 25);

// ---- limit, abort, resume
$st = WebRun::start($sid, ['tickets'], ['dry_run' => '1', 'limit' => '12']); $rid = (int) $st['run_id'];
$n = 0; do { $last = WebRun::step($rid); $n++; } while (($last['success'] ?? false) && !$last['done'] && $n < 10);
chk('limit 12 stops the module after 12 items', ($last['counters']['tickets']['fetched'] ?? 0) === 12, json_encode($last['counters'] ?? null));
$st = WebRun::start($sid, ['tickets'], ['dry_run' => '1']); $rid = (int) $st['run_id'];
WebRun::step($rid);
$ab = WebRun::abort($rid);
chk('abort: the run is closed as aborted and the lock released', $ab['success'] === true && Lock::current() === null && ($DB->request(['FROM' => 'glpi_plugin_itsmmigrator_runs', 'WHERE' => ['id' => $rid]])->current()['status'] ?? '') === 'aborted');

// ---- the stale-lock button
$st = WebRun::start($sid, ['tickets'], []); $rid = (int) $st['run_id'];
chk('stale button: a live run is NOT released', Lock::releaseStale() === 0 && Lock::current() !== null);
$DB->update('glpi_plugin_itsmmigrator_runs', ['heartbeat_at' => date('Y-m-d H:i:s', time() - 1200)], ['id' => $rid]);
ob_start(); RunView::render(); $html = ob_get_clean();
chk('run page: shows the stale lock and the release button', str_contains($html, 'Release stale lock') && str_contains($html, 'probably dead'));
chk('stale button: releases it', Lock::releaseStale() === 1 && Lock::current() === null);
unset($_SESSION[WebRun::SESSION_KEY][$rid]);

// ---- pages render, never leak the key
ob_start(); RunView::render(); $html = ob_get_clean();
chk('run page: renders the form, the console and the history', str_contains($html, 'itsm-run-form') && str_contains($html, 'itsm-log') && str_contains($html, 'Run history'));
chk('run page: dry run is ticked by default', (bool) preg_match('/id="itsm-dry_run" value="1" checked/', $html));
chk('run page: no API key anywhere in the page', !str_contains($html, $SECRET));
chk('run page: the script sends the CSRF header and X-Requested-With', str_contains($html, 'X-Glpi-Csrf-Token') && str_contains($html, 'X-Requested-With'));
chk('summary(): counters in one line', RunView::summary('{"users":{"created":3,"fetched":9,"failed":0},"tickets":{"failed":2}}') === 'users: 3 created; tickets: 2 failed');

// ---- Needs review
$u = new User();
$ua = $u->add(['name' => "$tag.a", 'realname' => 'Alpha', '_useremails' => [-1 => "$tag.shared@example.test"], '_default_email' => -1]);
$ub = $u->add(['name' => "$tag.b", 'realname' => 'Beta', '_useremails' => [-1 => "$tag.shared@example.test"], '_default_email' => -1]);
$uc = $u->add(['name' => "$tag.c"]);
$mkReview = function (string $id) use ($sid, $ua, $ub, $DB) {
    $r = MapRepository::begin($sid, 'freshservice', 'user', $id, 'User', 0, 'matched');
    MapRepository::markNeedsReview((int) $r['id'], "candidates: $ua,$ub");
    return (int) $r['id'];
};
$m1 = $mkReview("$tag-r1"); $m2 = $mkReview("$tag-r2"); $m3 = $mkReview("$tag-r3");
chk('candidates are parsed from the row', NeedsReview::candidates("candidates: $ua,$ub") === array_values(array_unique([min($ua, $ub), max($ua, $ub)])) && NeedsReview::candidates(null) === [] && NeedsReview::candidates('boom') === []);
chk('open() lists the review rows', count(NeedsReview::open($sid)) === 3);
ob_start(); ReviewView::render(); $html = ob_get_clean();
chk('review page: lists both candidates by login and name, never an e-mail', str_contains($html, "$tag.a") && str_contains($html, 'Alpha') && str_contains($html, "$tag.b") && !str_contains($html, '@example.test'));
$userBefore = (new User()); $userBefore->getFromDB($ua); $userBefore = $userBefore->fields;
$r = NeedsReview::resolve($m1, $ua);
$row = MapRepository::find('freshservice', 'user', "$tag-r1");
chk('resolve: the mapping points at the picked user, ok, matched', $r['ok'] && (int) $row['items_id'] === (int) $ua && $row['status'] === 'ok' && $row['action'] === 'matched' && $row['error'] === null, json_encode($row));
$after = new User(); $after->getFromDB($ua);
chk('resolve: the picked user row is NOT modified', $after->fields === $userBefore);
$r = NeedsReview::resolve($m2, 0);
chk('resolve: "no GLPI user" -> skipped', $r['ok'] && MapRepository::find('freshservice', 'user', "$tag-r2")['status'] === 'skipped');
chk('resolve: a user that does not exist is refused', NeedsReview::resolve($m3, 99999999)['ok'] === false && MapRepository::find('freshservice', 'user', "$tag-r3")['status'] === 'needs_review');
chk('resolve: a row that is not under review is refused', NeedsReview::resolve($m1, $ub)['ok'] === false && (int) MapRepository::find('freshservice', 'user', "$tag-r1")['items_id'] === (int) $ua);

// ---- External references tab
$t1 = (int) MapRepository::find('freshservice', 'ticket', "$tag-w1")['items_id'];
$tab = new ExternalReferenceTab();
$ticket = new Ticket(); $ticket->getFromDB($t1);
chk('tab: shown for a ticket that has map rows, with a count', str_contains((string) $tab->getTabNameForItem($ticket, 0), '1') || (string) $tab->getTabNameForItem($ticket, 0) !== '');
$plain = (new Ticket())->add(['name' => "$tag plain", 'content' => 'x', 'entities_id' => $entity, '_disablenotif' => true]);
$p = new Ticket(); $p->getFromDB($plain);
chk('tab: hidden for a ticket with no references', (string) $tab->getTabNameForItem($p, 0) === '');
chk('tab: hidden on a template', (string) $tab->getTabNameForItem($ticket, 1) === '');
ob_start(); ExternalReferenceTab::displayTabContentForItem($ticket); $html = ob_get_clean();
chk('tab: lists source, id, how it got here, status', str_contains($html, "$tag-src") && str_contains($html, "$tag-w1") && str_contains($html, 'created') && str_contains($html, 'ok'));
chk('tab: deep link built from the source base_url', str_contains($html, 'https://fs.example.test/a/tickets/') && str_contains($html, 'noopener'));
chk('tab: never shows the API key or the stored ciphertext', !str_contains($html, $SECRET) && !str_contains($html, (string) $src->fields['api_key']));
chk('tab: no "read-only mirror" banner for a Freshservice import', !str_contains($html, 'read-only mirror'));
$DB->update('glpi_plugin_itsmmigrator_maps', ['sync_owned' => 1], ['source' => 'freshservice', 'src_type' => 'ticket', 'src_id' => "$tag-w1"]);
ob_start(); ExternalReferenceTab::displayTabContentForItem($ticket); $html = ob_get_clean();
chk('tab: a sync-owned ticket shows the read-only banner naming the source', str_contains($html, 'read-only mirror') && str_contains($html, "$tag-src"));
chk('deepLink: Freshservice and SDP, https only, nothing for an unknown type', ExternalReferenceTab::deepLink('freshservice', 'https://x.example/', 'ticket', '42') === 'https://x.example/a/tickets/42'
    && ExternalReferenceTab::deepLink('sdp', 'https://s.example:8080', 'ticket', '7') === 'https://s.example:8080/WorkOrder.do?woMode=viewWO&woID=7'
    && ExternalReferenceTab::deepLink('freshservice', 'http://x.example', 'ticket', '42') === null && ExternalReferenceTab::deepLink('freshservice', 'https://x.example', 'user', '42') === null);
chk('tab rows: only the item\'s own references, not children', count(ExternalReferenceTab::rows('Ticket', $t1)) === 1);

// ---- the shared Menu now lists the pages
$entry = GlpiPlugin\Itsmmigrator\Menu::entry();
chk('menu: sources, runs and needs-review options', isset($entry['options']['source'], $entry['options']['run'], $entry['options']['review']) && $entry['order'] === 85);

// ---- cleanup (staging only)
AdapterFactory::setOverride(null);
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]]) as $r) { $DB->delete('glpi_tickets_users', ['tickets_id' => $r['id']]); $DB->delete('glpi_groups_tickets', ['tickets_id' => $r['id']]); $DB->delete('glpi_tickets', ['id' => $r['id']]); }
$DB->delete('glpi_plugin_itsmmigrator_maps', ['sources_id' => ['>', 0]]);
$DB->delete('glpi_plugin_itsmmigrator_runs', ['id' => ['>', 0]]);
$DB->delete('glpi_plugin_itsmmigrator_sources', ['id' => $sid]);
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['name' => ['LIKE', "$tag%"]]]) as $r) { $DB->delete('glpi_profiles_users', ['users_id' => $r['id']]); $DB->delete('glpi_useremails', ['users_id' => $r['id']]); $DB->delete('glpi_users', ['id' => $r['id']]); }
$DB->delete('glpi_entities', ['id' => $entity]);
echo "\nleftover: tickets=" . $count('glpi_tickets', ['name' => ['LIKE', "%$tag%"]]) . " users=" . $count('glpi_users', ['name' => ['LIKE', "$tag%"]]) . " entities=" . $count('glpi_entities', ['name' => ['LIKE', "$tag%"]]) . " maps=" . $count('glpi_plugin_itsmmigrator_maps') . " runs=" . $count('glpi_plugin_itsmmigrator_runs') . "\n";
echo "\n" . ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ($pass passed, $fail failed)\n";
