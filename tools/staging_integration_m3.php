<?php
// M3 integration check (tickets). STAGING ONLY: it writes tickets, followups, tasks, documents, users, a group,
// a category and an entity (all named itsmtest*) and removes them at the end. Never run it on production.
// Usage: php -c <php.ini> tools/staging_integration_m3.php [path-to-glpi-root]
$root = $argv[1] ?? 'C:/glpi-staging/app13/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('staging');
$kernel->boot();
global $DB;
$_SESSION['glpiID'] = 2; $_SESSION['glpi_use_mode'] = 0; $_SESSION['glpiname'] = 'glpi';
$_SESSION['glpiactiveentities'] = [0]; $_SESSION['glpiactive_entity'] = 0; $_SESSION['glpiis_ids_visible'] = 0;
$_SESSION['glpiactiveprofile'] = ['id' => 4, 'interface' => 'central'];
$prof = new Profile(); $prof->getFromDB(4); $_SESSION['glpiactiveprofile'] = $prof->fields + ['id' => 4];

use GlpiPlugin\Itsmmigrator\Adapter\Dto\{ExternalAttachment, ExternalNote, ExternalTask, ExternalTicket, ExternalWorklog, Page, TicketChildren};
use GlpiPlugin\Itsmmigrator\Adapter\SourceAdapterInterface;
use GlpiPlugin\Itsmmigrator\{Engine, MapRepository, Source};

$pass = 0; $fail = 0;
function chk(string $l, bool $ok, string $d = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "PASS $l\n"; } else { $fail++; echo "FAIL $l $d\n"; } }
function mapRow(string $type, string $id): ?array { return MapRepository::find('freshservice', $type, $id); }

$tag = 'itsmtest' . substr(md5((string) microtime(true)), 0, 6);
$entity = (new Entity())->add(['name' => "$tag-target", 'entities_id' => 0]);
$_SESSION['glpiactiveentities'] = [0, $entity];

// Mapped users, group and category, as the earlier modules would have left them.
$u = new User();
$requester = $u->add(['name' => "$tag.req", 'realname' => 'Requester']);
$agent     = $u->add(['name' => "$tag.agent", 'realname' => 'Agent']);
$group     = (new Group())->add(['name' => "$tag Group", 'entities_id' => $entity]);
$cat       = (new ITILCategory())->add(['name' => "$tag-Hardware", 'entities_id' => $entity]);
$cat2      = (new ITILCategory())->add(['name' => 'Laptop', 'itilcategories_id' => $cat, 'entities_id' => $entity]);

$src = new Source();
$sid = $src->add(['name' => "$tag-src", 'adapter' => 'freshservice', 'base_url' => 'https://fs.example.test', 'entities_id' => $entity, 'api_key' => 'k']);
$source = $src->fields; $source['id'] = $sid; unset($source['api_key']);

foreach ([['user', 'requester:501', 'User', $requester], ['user', 'agent:201', 'User', $agent], ['group', '301', 'Group', $group], ['itilcategory', "$tag-Hardware > Laptop", 'ITILCategory', $cat2]] as [$t, $id, $it, $gid]) {
    $r = MapRepository::begin($sid, 'freshservice', $t, $id, $it, (int) $gid, 'matched'); MapRepository::markOk((int) $r['id']);
}

$d = fn (string $s) => new DateTimeImmutable($s);
$mk = fn (string $id, string $status, array $extra = []) => new ExternalTicket('freshservice', $id, "$tag subject $id", "<p>Body $id</p>", $status, 'High', 'Incident',
    'requester:501', 'agent:201', '301', [$tag . '-Hardware', 'Laptop'], $d('2024-03-01T01:02:03Z'), $d('2024-03-05T09:00:00Z'), $id, $d('2024-03-02T04:05:06Z'));

class FakeAdapter implements SourceAdapterInterface {
    public array $failAttachment = []; public array $failNote = []; public int $childCalls = 0;
    public function __construct(public array $tickets, public array $children) {}
    public function getSourceKey(): string { return 'freshservice'; }
    public function supports(string $m): bool { return $m === 'tickets'; }
    public function testConnection(): array { return ['ok' => true, 'message' => '']; }
    public function fetchPage(string $m, int $p, ?DateTimeImmutable $since, ?string $cursor = null): Page { return new Page($this->tickets, false); }
    public function fetchTicketChildren(string $id): TicketChildren {
        $this->childCalls++;
        $c = $this->children[$id] ?? new TicketChildren();
        if ($this->failNote) {
            $c = new TicketChildren(array_values(array_filter($c->notes, fn ($n) => !in_array($n->srcId, $this->failNote, true))), $c->tasks, $c->worklogs, $c->attachments, $c->resolution, $c->resolvedAt, $c->closedAt);
        }
        return $c;
    }
    public function downloadAttachment(ExternalAttachment $a, string $dest): void {
        if (in_array($a->srcId, $this->failAttachment, true)) { throw new RuntimeException('download failed'); }
        file_put_contents($dest, "content of {$a->name} " . $a->srcId);
    }
}

$children = new TicketChildren(
    [new ExternalNote('freshservice', '7001', '1001', '<p>Public note</p>', false, '201', $d('2024-03-01T02:00:00Z')),
     new ExternalNote('freshservice', '7002', '1001', '<p>Private note</p>', true, '201', $d('2024-03-01T03:00:00Z'))],
    [new ExternalTask('freshservice', '8001', '1001', 'Reimage', 'Standard image', 'done', 'agent:201', $d('2024-03-01T04:00:00Z'))],
    [new ExternalWorklog('freshservice', '8501', '1001', 5400, 'Reimage and test', 'agent:201', $d('2024-03-02T01:00:00Z'))],
    [new ExternalAttachment('freshservice', '9001', '1001', 'photo.txt', 'https://s3.example.test/9001', 10)],
    '<p>Reimaged the laptop</p>', $d('2024-03-02T03:00:00Z'), null);
$adapter = new FakeAdapter([$mk('1001', 'Resolved'), $mk('1002', 'Open')], ['1001' => $children]);

$log = []; $logger = function (string $m) use (&$log) { $log[] = $m; };
$count = fn (string $t, array $w = []) => countElementsInTable($t, $w);

// ---- dry run writes nothing
$before = [$count('glpi_tickets'), $count('glpi_plugin_itsmmigrator_maps')];
$res = (new Engine($adapter))->run($source, 'cli', ['tickets'], ['dry_run' => true], $logger);
chk('dry run: nothing written', [$count('glpi_tickets'), $count('glpi_plugin_itsmmigrator_maps')] === $before);
chk('dry run: no child requests (rate-friendly)', $adapter->childCalls === 0);
chk('dry run: would create 2', ($res['counters']['tickets']['created'] ?? 0) === 2, json_encode($res['counters']));

// ---- real run
$res = (new Engine($adapter))->run($source, 'cli', ['tickets'], [], $logger);
chk('run: success, 2 created', $res['status'] === 'success' && ($res['counters']['tickets']['created'] ?? 0) === 2, json_encode($res));
$m = mapRow('ticket', '1001'); $tid = (int) ($m['items_id'] ?? 0);
chk('ticket 1001 mapped ok', $m && $m['status'] === 'ok' && $m['action'] === 'created' && $tid > 0);
$t = new Ticket(); $t->getFromDB($tid); $f = $t->fields;
chk('title is [FS#1001] subject', $f['name'] === "[FS#1001] $tag subject 1001", $f['name']);
chk('ticket is in the target entity', (int) $f['entities_id'] === (int) $entity);
chk('status Resolved -> Solved (5)', (int) $f['status'] === 5, (string) $f['status']);
chk('priority High -> 4, type Incident -> 1', (int) $f['priority'] === 4 && (int) $f['type'] === 1);
chk('date = source created_at (kept)', substr($f['date'], 0, 10) === (new DateTimeImmutable('2024-03-01T01:02:03Z'))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d'), $f['date']);
chk('date_mod = source updated_at (kept)', str_starts_with((string) $f['date_mod'], '2024-03-02'), (string) $f['date_mod']);
chk('solvedate = source resolved_at', str_starts_with((string) $f['solvedate'], '2024-03-02'), (string) $f['solvedate']);
chk('category mapped', (int) $f['itilcategories_id'] === (int) $cat2);
$tu = []; foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $tid]]) as $r) { $tu[] = $r['type'] . ':' . $r['users_id']; }
chk('requester and assignee mapped', $count('glpi_tickets_users', ['tickets_id' => $tid, 'type' => 1, 'users_id' => $requester]) === 1 && $count('glpi_tickets_users', ['tickets_id' => $tid, 'type' => 2, 'users_id' => $agent]) === 1, 'rows=' . implode(',', $tu) . " want 1:$requester 2:$agent");
chk('assignee group mapped', $count('glpi_groups_tickets', ['tickets_id' => $tid, 'type' => 2, 'groups_id' => $group]) === 1);
$t2 = new Ticket(); $t2->getFromDB((int) mapRow('ticket', '1002')['items_id']);
chk('ticket 1002: Open + assignee -> Assigned (2)', (int) $t2->fields['status'] === 2);
chk('followups: 2, one private', $count('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $tid]) === 2 && $count('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $tid, 'is_private' => 1]) === 1);
$fu = $DB->request(['FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tid], 'ORDER' => 'id'])->current();
chk('followup author is the mapped user, date kept', (int) $fu['users_id'] === (int) $agent && str_starts_with((string) $fu['date'], '2024-03-01'), json_encode([$fu['users_id'], $fu['date']]));
chk('tasks: the task and the worklog', $count('glpi_tickettasks', ['tickets_id' => $tid]) === 2);
chk('worklog carries actiontime 5400', $count('glpi_tickettasks', ['tickets_id' => $tid, 'actiontime' => 5400]) === 1);
chk('task state done (2)', $count('glpi_tickettasks', ['tickets_id' => $tid, 'state' => 2]) === 2);
chk('solution added', $count('glpi_itilsolutions', ['itemtype' => 'Ticket', 'items_id' => $tid]) === 1);
chk('document attached to the ticket', $count('glpi_documents_items', ['itemtype' => 'Ticket', 'items_id' => $tid]) === 1);
$di = $DB->request(['FROM' => 'glpi_documents_items', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tid]])->current();
$doc = new Document(); $doc->getFromDB((int) ($di['documents_id'] ?? 0));
chk('document is in the target entity, not recursive', (int) $doc->fields['entities_id'] === (int) $entity && (int) $doc->fields['is_recursive'] === 0);
chk('children have their own map rows', mapRow('note', '7001')['status'] === 'ok' && mapRow('task', '8001')['status'] === 'ok' && mapRow('worklog', '8501')['status'] === 'ok' && mapRow('document', '9001')['status'] === 'ok' && mapRow('solution', '1001')['status'] === 'ok');

// ---- idempotency
$snap = fn () => [$count('glpi_tickets'), $count('glpi_itilfollowups'), $count('glpi_tickettasks'), $count('glpi_itilsolutions'), $count('glpi_documents'), $count('glpi_documents_items'), $count('glpi_plugin_itsmmigrator_maps')];
$a = $snap();
$res = (new Engine($adapter))->run($source, 'cli', ['tickets'], [], $logger);
chk('second run: nothing new anywhere', $snap() === $a && ($res['counters']['tickets']['skipped'] ?? 0) === 2, json_encode($res['counters']));

// ---- resume: ticket row in_progress, children already in place -> same ticket, no duplicates
$DB->update('glpi_plugin_itsmmigrator_maps', ['status' => 'in_progress'], ['source' => 'freshservice', 'src_type' => 'ticket', 'src_id' => '1001']);
$res = (new Engine($adapter))->run($source, 'cli', ['tickets'], [], $logger);
chk('resume: same ticket, no duplicates', $snap() === $a && (int) mapRow('ticket', '1001')['items_id'] === $tid && mapRow('ticket', '1001')['status'] === 'ok');

// ---- a failing attachment: ticket failed, nothing duplicated, retry completes it
$DB->delete('glpi_plugin_itsmmigrator_maps', ['source' => 'freshservice', 'src_type' => 'document', 'src_id' => '9001']);
$DB->delete('glpi_documents_items', ['itemtype' => 'Ticket', 'items_id' => $tid]);
$DB->update('glpi_plugin_itsmmigrator_maps', ['status' => 'in_progress'], ['source' => 'freshservice', 'src_type' => 'ticket', 'src_id' => '1001']);
$adapter->failAttachment = ['9001'];
$res = (new Engine($adapter))->run($source, 'cli', ['tickets'], [], $logger);
chk('failed child: ticket marked failed, run partial', mapRow('ticket', '1001')['status'] === 'failed' && $res['status'] === 'partial', json_encode([mapRow('ticket', '1001')['status'], $res['status']]));
chk('failed child: no duplicate followups or tasks', $count('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $tid]) === 2 && $count('glpi_tickettasks', ['tickets_id' => $tid]) === 2);
$adapter->failAttachment = [];
(new Engine($adapter))->run($source, 'cli', ['tickets'], [], $logger);
chk('failed ticket is skipped without --retry-failed', mapRow('ticket', '1001')['status'] === 'failed');
$res = (new Engine($adapter))->run($source, 'cli', ['tickets'], ['retry_failed' => true], $logger);
chk('--retry-failed completes it, still 1 ticket', mapRow('ticket', '1001')['status'] === 'ok' && $count('glpi_tickets', ['name' => ['LIKE', "%$tag subject 1001"]]) === 1 && $count('glpi_documents_items', ['itemtype' => 'Ticket', 'items_id' => $tid]) === 1);
chk('same file is one Document (sha1 natural key)', $count('glpi_documents', ['entities_id' => $entity]) === 1);

// ---- natural key: map rows lost, tickets adopted by their [FS#id] title
$DB->delete('glpi_plugin_itsmmigrator_maps', ['source' => 'freshservice', 'src_type' => ['ticket', 'note', 'task', 'worklog', 'solution', 'document']]);
$tickets = $count('glpi_tickets'); $fus = $count('glpi_itilfollowups'); $tasks = $count('glpi_tickettasks'); $sol = $count('glpi_itilsolutions');
$res = (new Engine($adapter))->run($source, 'cli', ['tickets'], [], $logger);
chk('natural key: adopted, no new ticket', $count('glpi_tickets') === $tickets && ($res['counters']['tickets']['matched'] ?? 0) === 2, json_encode($res['counters']));
chk('natural key: followups, tasks, solution adopted, none duplicated', $count('glpi_itilfollowups') === $fus && $count('glpi_tickettasks') === $tasks && $count('glpi_itilsolutions') === $sol);

// ---- cross-source link: an SDP row with origin_ref already points at a GLPI ticket
$other = (new Ticket())->add(['name' => "$tag [SDP-made]", 'content' => 'x', 'entities_id' => $entity, '_disablenotif' => true]);
$r = MapRepository::begin($sid, 'sdp', 'ticket', 'sdp-9', 'Ticket', (int) $other, 'created', ['origin_ref' => 'freshservice:ticket:2002']); MapRepository::markOk((int) $r['id']);
$adapter->tickets = [$mk('2002', 'Open')];
$before = $count('glpi_tickets');
$res = (new Engine($adapter))->run($source, 'cli', ['tickets'], [], $logger);
$m = mapRow('ticket', '2002');
chk('link: FS ticket linked to the SDP-made GLPI ticket, nothing created', $m && $m['action'] === 'linked' && (int) $m['items_id'] === (int) $other && $count('glpi_tickets') === $before && ($res['counters']['tickets']['linked'] ?? 0) === 1, json_encode($res['counters']));

// ---- isolation: every ticket the plugin created is in the target entity
$bad = $count('glpi_tickets', ['name' => ['LIKE', "[FS#%$tag%"], ['NOT' => ['entities_id' => $entity]]]);
chk('isolation: no [FS#] ticket outside the target entity', $bad === 0);

// ---- cleanup (staging only)
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity]]) as $r) {
    foreach (['glpi_itilfollowups' => 'items_id', 'glpi_itilsolutions' => 'items_id', 'glpi_documents_items' => 'items_id'] as $tb => $col) { $DB->delete($tb, [$col => $r['id'], 'itemtype' => 'Ticket']); }
    foreach (['glpi_tickettasks' => 'tickets_id', 'glpi_tickets_users' => 'tickets_id', 'glpi_groups_tickets' => 'tickets_id'] as $tb => $col) { $DB->delete($tb, [$col => $r['id']]); }
    $DB->delete('glpi_tickets', ['id' => $r['id']]);
}
$DB->delete('glpi_documents', ['entities_id' => $entity]);
$DB->delete('glpi_plugin_itsmmigrator_maps', ['sources_id' => ['>', 0]]);
$DB->delete('glpi_plugin_itsmmigrator_runs', ['id' => ['>', 0]]);
$DB->delete('glpi_plugin_itsmmigrator_sources', ['id' => $sid]);
$DB->delete('glpi_itilcategories', ['entities_id' => $entity]); $DB->delete('glpi_groups', ['entities_id' => $entity]);
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['name' => ['LIKE', "$tag%"]]]) as $r) { $DB->delete('glpi_profiles_users', ['users_id' => $r['id']]); $DB->delete('glpi_useremails', ['users_id' => $r['id']]); $DB->delete('glpi_users', ['id' => $r['id']]); }
$DB->delete('glpi_entities', ['id' => $entity]);
echo "\nleftover: tickets=" . $count('glpi_tickets', ['entities_id' => $entity]) . " users=" . $count('glpi_users', ['name' => ['LIKE', "$tag%"]]) . " entities=" . $count('glpi_entities', ['name' => ['LIKE', "$tag%"]]) . " maps=" . $count('glpi_plugin_itsmmigrator_maps') . "\n";
echo "\n" . ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ($pass passed, $fail failed)\n";
if ($fail) { echo "--- log (tail) ---\n" . implode("\n", array_slice($log, -30)) . "\n"; }
