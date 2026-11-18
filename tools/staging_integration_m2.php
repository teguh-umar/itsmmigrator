<?php
// M2 integration check. STAGING ONLY: it writes users, groups, categories and entities (all named itsmtest*)
// and removes them at the end. Never run it on production.
// Usage: php -c <php.ini> tools/staging_integration_m2.php [path-to-glpi-root]
$root = $argv[1] ?? 'C:/glpi-staging/app13/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('staging');
$kernel->boot();
global $DB;
$_SESSION['glpiID'] = 2; $_SESSION['glpi_use_mode'] = 0;
$_SESSION['glpiactiveentities'] = [0, 1, 2, 3]; $_SESSION['glpiactive_entity'] = 0;
$_SESSION['glpiactiveprofile'] = ['id' => 4, 'interface' => 'central'];
$_SESSION['glpiname'] = 'glpi';

use GlpiPlugin\Itsmmigrator\Adapter\Dto\{ExternalCategory, ExternalGroup, ExternalUser, Page};
use GlpiPlugin\Itsmmigrator\Adapter\SourceAdapterInterface;
use GlpiPlugin\Itsmmigrator\{Engine, Lock, LockException, MapRepository, Source};

$pass = 0; $fail = 0;
function chk(string $l, bool $ok, string $d = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "PASS $l\n"; } else { $fail++; echo "FAIL $l $d\n"; } }

$tag = 'itsmtest' . substr(md5((string) microtime(true)), 0, 6);
$cleanup = [];

// Entity tree: root(0) > parent > target ; parent > sibling
$e = new Entity();
$parent  = $e->add(['name' => "$tag-parent",  'entities_id' => 0]);
$target  = $e->add(['name' => "$tag-target",  'entities_id' => $parent]);
$sibling = $e->add(['name' => "$tag-sibling", 'entities_id' => $parent]);
// Entity::add in CLI leaves every entity under root, so build the tree by hand (staging only).
$DB->update('glpi_entities', ['level' => 1, 'completename' => "$tag-parent", 'ancestors_cache' => '[0]', 'sons_cache' => json_encode([$target => $target, $sibling => $sibling])], ['id' => $parent]);
foreach ([$target, $sibling] as $child) {
    $nm = $child === $target ? "$tag-target" : "$tag-sibling";
    $DB->update('glpi_entities', ['entities_id' => $parent, 'level' => 2, 'completename' => "$tag-parent > $nm", 'ancestors_cache' => json_encode([0, $parent])], ['id' => $child]);
}
global $GLPI_CACHE; $GLPI_CACHE->clear(); // Entity::add cached [0] as the ancestors
$cleanup[] = ['Entity', $sibling]; $cleanup[] = ['Entity', $target]; $cleanup[] = ['Entity', $parent];
$_SESSION['glpiactiveentities'] = [0, $parent, $target, $sibling];

// Fixtures: a recursive group "ITOC" in the parent; a non-recursive group "OnlyParent" in the parent;
// a category path in the sibling only.
$g = new Group();
$itoc = $g->add(['name' => "$tag ITOC", 'entities_id' => $parent, 'is_recursive' => 1]);
$onlyP = $g->add(['name' => "$tag OnlyParent", 'entities_id' => $parent, 'is_recursive' => 0]);
$cleanup[] = ['Group', $itoc]; $cleanup[] = ['Group', $onlyP];
$cat = new ITILCategory();
$sibCat = $cat->add(['name' => "$tag-Net", 'entities_id' => $sibling, 'is_recursive' => 1]);
$parCat = $cat->add(['name' => "$tag-Hardware", 'entities_id' => $parent, 'is_recursive' => 1]);

// GLPI users: existing AD-like user (email match), two users sharing an email, a login-only user.
$u = new User();
$ann = $u->add(['name' => "$tag.ann", 'realname' => 'Ann', 'is_active' => 1, '_useremails' => [-1 => "$tag.ann@example.test"], '_default_email' => -1, 'authtype' => 3]);
$d1  = $u->add(['name' => "$tag.dup1", '_useremails' => [-1 => "$tag.dup@example.test"], '_default_email' => -1]);
$d2  = $u->add(['name' => "$tag.dup2", '_useremails' => [-1 => "$tag.dup@example.test"], '_default_email' => -1]);
$lg  = $u->add(['name' => "$tag.loginonly"]);
$inactive = $u->add(['name' => "$tag.gone", 'is_active' => 0, '_useremails' => [-1 => "$tag.gone@example.test"], '_default_email' => -1]);
foreach ([$ann, $d1, $d2, $lg, $inactive] as $id) { $cleanup[] = ['User', $id]; }
$annBefore = (new User()); $annBefore->getFromDB($ann); $annBefore = $annBefore->fields;

// Source row
$src = new Source();
$sid = $src->add(['name' => "$tag-src", 'adapter' => 'freshservice', 'base_url' => 'https://fs.example.test', 'entities_id' => $target,
    'api_key' => 'k', 'options' => json_encode(['email_aliases' => [['old.example.test', 'new.example.test']]])]);
chk('source created', (int) $sid > 0);
$source = $src->fields; $source['id'] = $sid;
$srcAdapter = 'freshservice';

class FakeAdapter implements SourceAdapterInterface {
    public function __construct(private array $mods) {}
    public function getSourceKey(): string { return 'freshservice'; }
    public function supports(string $m): bool { return isset($this->mods[$m]); }
    public function testConnection(): array { return ['ok' => true, 'message' => '']; }
    public function fetchPage(string $m, int $page, ?DateTimeImmutable $since): Page {
        $pages = $this->mods[$m]; return new Page($pages[$page - 1] ?? [], $page < count($pages));
    }
}

$users = [
    new ExternalUser('freshservice', 'u1', 'Ann', ["$tag.ann@example.test"]),                         // email -> matched
    new ExternalUser('freshservice', 'u2', 'Ann alias', ["$tag.ann@old.example.test"]),                 // wait: alias of ann's address? no, ann is @example.test
    new ExternalUser('freshservice', 'u3', 'Dup', ["$tag.dup@example.test"]),                           // two users -> needs_review
    new ExternalUser('freshservice', 'u4', 'LoginOnly', [], "CORP\\$tag.loginonly"),                    // login -> matched
    new ExternalUser('freshservice', 'u5', 'Gone', ["$tag.gone@example.test"]),                         // inactive still matched
    new ExternalUser('freshservice', 'u6', 'Nobody', ["$tag.nobody@example.test"]),                     // not found -> skipped (no create)
];
$groups = [
    new ExternalGroup('freshservice', 'g1', "$tag itoc", ['u1']),                                       // matches parent recursive group
    new ExternalGroup('freshservice', 'g2', "$tag OnlyParent"),                                         // parent non-recursive -> created in target
    new ExternalGroup('freshservice', 'g3', "$tag Brand New"),                                          // created
];
$cats = [
    new ExternalCategory('freshservice', 'c1', ["$tag-Hardware"]),                                      // matches parent recursive
    new ExternalCategory('freshservice', 'c2', ["$tag-Net"]),                                           // exists only in sibling -> created in target
    new ExternalCategory('freshservice', 'c3', ["$tag-Hardware", 'Laptop']),                            // new child under matched parent
];
$adapter = new FakeAdapter(['users' => [array_slice($users, 0, 3), array_slice($users, 3)], 'groups' => [$groups], 'categories' => [$cats]]);

$log = [];
$logger = function (string $m) use (&$log) { $log[] = $m; };

// ---- dry run: nothing is written
$mapsBefore = countElementsInTable('glpi_plugin_itsmmigrator_maps');
$groupsBefore = countElementsInTable('glpi_groups');
$res = (new Engine($adapter))->run($source, 'cli', [], ['dry_run' => true], $logger);
chk('dry run: status success', $res['status'] === 'success', json_encode($res));
chk('dry run: no map rows written', countElementsInTable('glpi_plugin_itsmmigrator_maps') === $mapsBefore);
chk('dry run: no group created', countElementsInTable('glpi_groups') === $groupsBefore);
chk('dry run: would create 2 groups', ($res['counters']['groups']['created'] ?? 0) === 2, json_encode($res['counters']['groups'] ?? null));

// ---- real run
$res = (new Engine($adapter))->run($source, 'cli', [], [], $logger);
$c = $res['counters'];
chk('run: status success', $res['status'] === 'success', json_encode($res));
chk('users: fetched 6', ($c['users']['fetched'] ?? 0) === 6);
// u1 ann matched, u2 alias(@old) -> alias to @new, ann is @example.test so NOT matched -> skipped
chk('users: matched = ann + loginonly + gone = 3', ($c['users']['matched'] ?? 0) === 3, json_encode($c['users'] ?? null));
chk('users: needs_review = 1 (shared e-mail)', ($c['users']['needs_review'] ?? 0) === 1);
chk('users: skipped = 2 (alias with no hit, nobody)', ($c['users']['skipped'] ?? 0) === 2, json_encode($c['users'] ?? null));
chk('users: none created without the flag', ($c['users']['created'] ?? 0) === 0);
$m = MapRepository::find('freshservice', 'user', 'u1');
chk('u1 -> Ann, ok', $m && (int) $m['items_id'] === (int) $ann && $m['status'] === 'ok' && $m['action'] === 'matched');
$m = MapRepository::find('freshservice', 'user', 'u3');
chk('u3 needs_review lists both candidates', $m && $m['status'] === 'needs_review' && str_contains((string) $m['error'], (string) $d1) && str_contains((string) $m['error'], (string) $d2), json_encode($m));
$m = MapRepository::find('freshservice', 'user', 'u4');
chk('u4 matched by login (DOMAIN\\ stripped)', $m && (int) $m['items_id'] === (int) $lg);
$m = MapRepository::find('freshservice', 'user', 'u5');
chk('u5 inactive user matched', $m && (int) $m['items_id'] === (int) $inactive);
$after = new User(); $after->getFromDB($ann);
chk('matched user row is unchanged', $after->fields === $annBefore);
$after = new User(); $after->getFromDB($inactive);
chk('inactive user is still inactive', (int) $after->fields['is_active'] === 0);
chk('no Profile_User for matched users (assign_profiles=none)', countElementsInTable('glpi_profiles_users', ['users_id' => [$ann, $lg, $inactive], 'entities_id' => $target]) === 0);

$m = MapRepository::find('freshservice', 'group', 'g1');
chk('g1 matched the recursive parent group, nothing new in target', $m && (int) $m['items_id'] === (int) $itoc && $m['action'] === 'matched', json_encode($m));
$m2 = MapRepository::find('freshservice', 'group', 'g2');
chk('g2 (non-recursive parent group) -> created in target', $m2 && $m2['action'] === 'created' && (int) $m2['items_id'] !== (int) $onlyP);
$gr = new Group(); $gr->getFromDB((int) $m2['items_id']);
chk('created group: target entity, not recursive', (int) $gr->fields['entities_id'] === (int) $target && (int) $gr->fields['is_recursive'] === 0);
chk('created group is flagged imported', str_contains((string) $gr->fields['comment'], 'Imported from freshservice'));
chk('matched parent group got NO members (option off)', countElementsInTable('glpi_groups_users', ['groups_id' => $itoc]) === 0);

$m = MapRepository::find('freshservice', 'itilcategory', 'c1');
chk('c1 matched the recursive parent category', $m && (int) $m['items_id'] === (int) $parCat && $m['action'] === 'matched');
$m = MapRepository::find('freshservice', 'itilcategory', 'c2');
chk('c2 (only in a sibling entity) created in target', $m && $m['action'] === 'created' && (int) $m['items_id'] !== (int) $sibCat);
$cc = new ITILCategory(); $cc->getFromDB((int) $m['items_id']);
chk('created category: target entity, not recursive', (int) $cc->fields['entities_id'] === (int) $target && (int) $cc->fields['is_recursive'] === 0);
$m = MapRepository::find('freshservice', 'itilcategory', 'c3');
$cc = new ITILCategory(); $cc->getFromDB((int) $m['items_id']);
chk('c3 created under the matched parent', (int) $cc->fields['itilcategories_id'] === (int) $parCat && (int) $cc->fields['entities_id'] === (int) $target);

// ---- isolation: everything the plugin created is in the target entity, non-recursive
$bad = 0;
foreach ($DB->request(['FROM' => 'glpi_plugin_itsmmigrator_maps', 'WHERE' => ['action' => 'created', 'sources_id' => $sid]]) as $row) {
    $t = getItemForItemtype($row['itemtype']);
    if ($t && $t->getFromDB((int) $row['items_id'])) {
        if (isset($t->fields['entities_id']) && ((int) $t->fields['entities_id'] !== (int) $target || (int) ($t->fields['is_recursive'] ?? 0) !== 0)) { $bad++; }
    }
}
chk('isolation: zero created items outside target / recursive', $bad === 0);

// ---- idempotency: a second run changes nothing
$maps = countElementsInTable('glpi_plugin_itsmmigrator_maps'); $grp = countElementsInTable('glpi_groups'); $cats2 = countElementsInTable('glpi_itilcategories');
$res = (new Engine($adapter))->run($source, 'cli', [], [], $logger);
chk('second run: same map rows', countElementsInTable('glpi_plugin_itsmmigrator_maps') === $maps);
chk('second run: no new group / category', countElementsInTable('glpi_groups') === $grp && countElementsInTable('glpi_itilcategories') === $cats2);
chk('second run: everything skipped', array_sum(array_map(fn ($k) => ($res['counters'][$k]['created'] ?? 0) + ($res['counters'][$k]['matched'] ?? 0), ['users', 'groups', 'categories'])) === 0, json_encode($res['counters']));

// ---- resume: an in_progress row with items_id reuses the item, never adds a new one
$DB->update('glpi_plugin_itsmmigrator_maps', ['status' => 'in_progress'], ['source' => 'freshservice', 'src_type' => 'group', 'src_id' => 'g3']);
$grp = countElementsInTable('glpi_groups');
$res = (new Engine($adapter))->run($source, 'cli', ['groups'], [], $logger);
chk('resume: no duplicate group', countElementsInTable('glpi_groups') === $grp);
chk('resume: row back to ok', (MapRepository::find('freshservice', 'group', 'g3')['status'] ?? '') === 'ok');

// ---- failed row: skipped by default, retried with the flag
$DB->update('glpi_plugin_itsmmigrator_maps', ['status' => 'failed', 'error' => 'x'], ['source' => 'freshservice', 'src_type' => 'group', 'src_id' => 'g3']);
(new Engine($adapter))->run($source, 'cli', ['groups'], [], $logger);
chk('failed row skipped without --retry-failed', (MapRepository::find('freshservice', 'group', 'g3')['status'] ?? '') === 'failed');
(new Engine($adapter))->run($source, 'cli', ['groups'], ['retry_failed' => true], $logger);
chk('failed row retried with --retry-failed', (MapRepository::find('freshservice', 'group', 'g3')['status'] ?? '') === 'ok');

// ---- lock
$rid = Lock::acquire($sid, 'cli', ['users'], false);
try { Lock::acquire($sid, 'web', ['users'], false); chk('second runner refused', false); }
catch (LockException $ex) { chk('second runner refused with owner info', str_contains($ex->getMessage(), 'Run already active') && str_contains($ex->getMessage(), 'cli'), $ex->getMessage()); }
try { (new Engine($adapter))->run($source, 'cron', [], [], $logger); chk('engine refuses while locked', false); }
catch (LockException $ex) { chk('engine refuses while locked', true); }
Lock::release($rid, 'success');
// stale: heartbeat 20 min ago
$rid = Lock::acquire($sid, 'cli', ['users'], false);
$DB->update('glpi_plugin_itsmmigrator_runs', ['heartbeat_at' => date('Y-m-d H:i:s', time() - 1200)], ['id' => $rid]);
try { Lock::acquire($sid, 'web', ['users'], false); chk('stale run still blocks without force', false); }
catch (LockException $ex) { chk('stale run still blocks without force', $ex->stale === true); }
$rid2 = Lock::acquire($sid, 'web', ['users'], false, null, true);
chk('force-unlock takes a stale lock', $rid2 > 0);
$st = $DB->request(['SELECT' => ['status'], 'FROM' => 'glpi_plugin_itsmmigrator_runs', 'WHERE' => ['id' => $rid]])->current();
chk('stale run marked aborted', ($st['status'] ?? '') === 'aborted');
Lock::release($rid2, 'success');
$st = $DB->request(['SELECT' => ['status', 'counters'], 'FROM' => 'glpi_plugin_itsmmigrator_runs', 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
chk('no run left in running state', countElementsInTable('glpi_plugin_itsmmigrator_runs', ['status' => 'running']) === 0);

// ---- create-missing-users + leak guard
$adapter2 = new FakeAdapter(['users' => [[new ExternalUser('freshservice', 'u9', 'Newbie', ["$tag.newbie@example.test"], "$tag.newbie")]]]);
$res = (new Engine($adapter2))->run($source, 'cli', ['users'], ['create_missing_users' => true], $logger);
$m = MapRepository::find('freshservice', 'user', 'u9');
chk('local user created with the flag', $m && $m['action'] === 'created' && (int) $m['items_id'] > 0, json_encode($res['counters']));
if ($m) {
    $nu = new User(); $nu->getFromDB((int) $m['items_id']); $cleanup[] = ['User', (int) $m['items_id']];
    chk('created user is inactive by default', (int) $nu->fields['is_active'] === 0);
    chk('created user is flagged imported', str_contains((string) $nu->fields['comment'], 'Imported from freshservice'));
    chk('created user has no profile outside the target entity', countElementsInTable('glpi_profiles_users', ['users_id' => (int) $m['items_id'], ['NOT' => ['entities_id' => $target]]]) === 0);
}

// ---- cleanup everything this script created (staging only)
$DB->delete('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid]);
$DB->delete('glpi_plugin_itsmmigrator_runs', ['sources_id' => $sid]);
$src->delete(['id' => $sid], true);
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_itilcategories', 'WHERE' => ['name' => ['LIKE', "$tag%"]]]) as $r) { $DB->delete('glpi_itilcategories', ['id' => $r['id']]); }
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_groups', 'WHERE' => ['name' => ['LIKE', "$tag%"]]]) as $r) { $DB->delete('glpi_groups_users', ['groups_id' => $r['id']]); $DB->delete('glpi_groups', ['id' => $r['id']]); }
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['name' => ['LIKE', "$tag%"]]]) as $r) { $DB->delete('glpi_profiles_users', ['users_id' => $r['id']]); $DB->delete('glpi_useremails', ['users_id' => $r['id']]); $DB->delete('glpi_users', ['id' => $r['id']]); }
foreach ([$sibling, $target, $parent] as $id) { $DB->delete('glpi_entities', ['id' => $id]); }
echo "\nleftover test rows: users=" . countElementsInTable('glpi_users', ['name' => ['LIKE', "$tag%"]]) . " groups=" . countElementsInTable('glpi_groups', ['name' => ['LIKE', "$tag%"]]) . " entities=" . countElementsInTable('glpi_entities', ['name' => ['LIKE', "$tag%"]]) . "\n";
echo "\n" . ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ($pass passed, $fail failed)\n";
if ($log && $fail) { echo "--- log ---\n" . implode("\n", array_slice($log, -25)) . "\n"; }
