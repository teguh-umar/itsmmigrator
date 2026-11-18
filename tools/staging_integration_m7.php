<?php
// M7 integration check (verify). STAGING ONLY: it creates tickets, groups, categories, users, documents and entities
// (all named itsmtest*) with deliberate defects, checks that `verify` finds each one, and removes everything.
// Usage: php -c <php.ini> tools/staging_integration_m7.php [path-to-glpi-root]
$root = $argv[1] ?? 'C:/glpi-staging/app13/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('staging');
$kernel->boot();
global $DB, $GLPI_CACHE;
$_SESSION['glpiID'] = 2; $_SESSION['glpi_use_mode'] = 0; $_SESSION['glpiname'] = 'glpi';
$prof = new Profile(); $prof->getFromDB(4); $_SESSION['glpiactiveprofile'] = $prof->fields + ['id' => 4];
$_SESSION['glpiactiveentities'] = [0]; $_SESSION['glpiactive_entity'] = 0;

use GlpiPlugin\Itsmmigrator\{MapRepository, Source, Verifier};

$pass = 0; $fail = 0;
function chk(string $l, bool $ok, string $d = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "PASS $l\n"; } else { $fail++; echo "FAIL $l $d\n"; } }
$count = fn (string $t, array $w = []) => countElementsInTable($t, $w);

$tag = 'itsmtest' . substr(md5((string) microtime(true)), 0, 6);

// Tree: root > parent > target ; parent > other. Built by hand (Entity::add in CLI leaves everything under root).
$e = new Entity();
$parent = $e->add(['name' => "$tag-parent", 'entities_id' => 0]);
$target = $e->add(['name' => "$tag-target", 'entities_id' => 0]);
$other  = $e->add(['name' => "$tag-other", 'entities_id' => 0]);
$DB->update('glpi_entities', ['level' => 1, 'completename' => "$tag-parent", 'ancestors_cache' => '[0]'], ['id' => $parent]);
foreach ([$target, $other] as $c) { $DB->update('glpi_entities', ['entities_id' => $parent, 'level' => 2, 'completename' => "$tag-parent > x$c", 'ancestors_cache' => json_encode([0, $parent])], ['id' => $c]); }
$GLPI_CACHE->clear();
$_SESSION['glpiactiveentities'] = [0, $parent, $target, $other];

$src = new Source();
$sid = $src->add(['name' => "$tag-src", 'adapter' => 'freshservice', 'base_url' => 'https://fs.example.test', 'entities_id' => $target, 'api_key' => 'k']);

$mk = function (string $type, string $id, string $itemtype, int $items, string $action = 'created', string $status = 'ok', array $extra = []) use ($sid) {
    $r = MapRepository::begin($sid, 'freshservice', $type, $id, $itemtype, $items, $action, $extra);
    $DB = $GLOBALS['DB']; $DB->update('glpi_plugin_itsmmigrator_maps', ['status' => $status], ['id' => $r['id']]);
    return $r;
};
$tk = fn (string $name, int $entity) => (new Ticket())->add(['name' => $name, 'content' => 'x', 'entities_id' => $entity, '_disablenotif' => true]);

// ---- 1. a clean source: scoped checks find nothing
$t1 = $tk("[FS#$tag-1] clean", $target);
$g1 = (new Group())->add(['name' => "$tag Clean", 'entities_id' => $target, 'is_recursive' => 0]);
$mk('ticket', "$tag-1", 'Ticket', $t1); $mk('group', 'g-clean', 'Group', $g1);
$res = (new Verifier())->run($sid);
$scoped = ['missing', 'unfinished', 'shadow', 'dup_origin', 'dup_docs', 'isolation', 'sync_owned'];
chk('clean source: every scoped check is clean', array_sum(array_map(fn ($k) => $res[$k]['total'], $scoped)) === 0, json_encode(array_map(fn ($k) => [$k, $res[$k]['findings']], array_filter($scoped, fn ($k) => $res[$k]['total'] > 0))));
$baseDupUsers = $res['dup_users']['total']; $baseDupTitles = $res['dup_titles']['total'];

// ---- 2. missing / trashed / moved
$mk('ticket', "$tag-gone", 'Ticket', 987654);
$tTrash = $tk("[FS#$tag-trash] trashed", $target); $mk('ticket', "$tag-trash", 'Ticket', $tTrash);
$DB->update('glpi_tickets', ['is_deleted' => 1], ['id' => $tTrash]);
$tMoved = $tk("[FS#$tag-moved] moved", $other); $mk('ticket', "$tag-moved", 'Ticket', $tMoved); // created, but lives in another entity
// a MATCHED item elsewhere is fine and must not be reported
$gElse = (new Group())->add(['name' => "$tag Elsewhere", 'entities_id' => $parent, 'is_recursive' => 1]);
$mk('group', 'g-matched', 'Group', $gElse, 'matched');
$res = (new Verifier())->run($sid);
$m = implode("\n", $res['missing']['findings']);
chk('missing: a deleted item is found', str_contains($m, 'missing in GLPI'), $m);
chk('missing: a trashed item is found', str_contains($m, 'in the trash'));
chk('missing: an item moved out of the target is found', str_contains($m, "moved to entity $other"));
chk('missing: a MATCHED item in another entity is NOT reported', !str_contains($m, "Group #$gElse"));
chk('missing: exactly 3 findings', $res['missing']['total'] === 3, (string) $res['missing']['total']);

// ---- 3. unfinished
$mk('user', 'u-prog', 'User', 0, 'matched', 'in_progress');
$mk('user', 'u-rev', 'User', 0, 'matched', 'needs_review', ['display_id' => null]); $DB->update('glpi_plugin_itsmmigrator_maps', ['error' => 'candidates: 1,2'], ['src_id' => 'u-rev']);
$mk('user', 'u-fail', 'User', 0, 'matched', 'failed'); $DB->update('glpi_plugin_itsmmigrator_maps', ['error' => 'boom'], ['src_id' => 'u-fail']);
$res = (new Verifier())->run($sid);
chk('unfinished: in_progress, needs_review and failed rows listed', $res['unfinished']['total'] === 3 && str_contains(implode(' ', $res['unfinished']['findings']), 'candidates: 1,2'));

// ---- 4. shadow: a created group with the same name as a recursive one in the parent
$gShadow = (new Group())->add(['name' => "$tag Elsewhere", 'entities_id' => $target, 'is_recursive' => 0]);
$mk('group', 'g-shadow', 'Group', $gShadow);
$res = (new Verifier())->run($sid);
chk('shadow: a created group duplicating a visible parent group is found', $res['shadow']['total'] === 1 && str_contains($res['shadow']['findings'][0], "#$gElse"), json_encode($res['shadow']));

// ---- 5. duplicates
$tDup = $tk("[FS#$tag-1] duplicate", $target);                       // second ticket with the same [FS#id] in the entity
$mk('ticket', "$tag-dupmap", 'Ticket', $tDup, 'created', 'ok', ['origin_ref' => "freshservice:ticket:$tag"]);
$mk('ticket', "$tag-dupmap2", 'Ticket', $t1, 'created', 'ok', ['origin_ref' => "freshservice:ticket:$tag"]);   // two GLPI tickets, one origin_ref
$res = (new Verifier())->run($sid);
chk('dup_titles: the same [FS#id] twice in one entity is found', $res['dup_titles']['total'] === $baseDupTitles + 1 && str_contains(implode(' ', $res['dup_titles']['findings']), "[FS#$tag-1]"), json_encode($res['dup_titles']));
chk('dup_origin: two GLPI tickets for one origin_ref is found', $res['dup_origin']['total'] === 1 && str_contains($res['dup_origin']['findings'][0], "freshservice:ticket:$tag"));

$doc = (new Document())->add(['name' => "$tag doc", 'entities_id' => $target, 'is_recursive' => 0]);
$DB->update('glpi_documents', ['sha1sum' => sha1($tag)], ['id' => $doc]);
$doc2 = (new Document())->add(['name' => "$tag doc2", 'entities_id' => $target, 'is_recursive' => 0]);
$DB->update('glpi_documents', ['sha1sum' => sha1($tag)], ['id' => $doc2]);
$mk('document', 'd1', 'Document', $doc); $mk('document', 'd2', 'Document', $doc2);
// GLPI's unique key includes timeline_position, so a duplicate link can only exist at another position.
$DB->insert('glpi_documents_items', ['documents_id' => $doc, 'itemtype' => 'Ticket', 'items_id' => $t1, 'entities_id' => $target, 'timeline_position' => 0, 'date_creation' => date('Y-m-d H:i:s')]);
$DB->insert('glpi_documents_items', ['documents_id' => $doc, 'itemtype' => 'Ticket', 'items_id' => $t1, 'entities_id' => $target, 'timeline_position' => 1, 'date_creation' => date('Y-m-d H:i:s')]);
$res = (new Verifier())->run($sid);
$d = implode("\n", $res['dup_docs']['findings']);
chk('dup_docs: a document linked twice to one ticket is found', str_contains($d, "document #$doc is linked 2 times"), $d);
chk('dup_docs: one file stored twice in one entity is found', str_contains($d, "documents $doc, $doc2") || str_contains($d, "documents $doc2, $doc"), $d);

// ---- 6. duplicate users (global check: compare with the baseline)
$u = new User();
$ua = $u->add(['name' => "$tag.a", '_useremails' => [-1 => "$tag.same@example.test"], '_default_email' => -1]);
$ub = $u->add(['name' => "$tag.b", '_useremails' => [-1 => "$tag.same@example.test"], '_default_email' => -1]);
$res = (new Verifier())->run($sid);
chk('dup_users: two users sharing an address are found (ids only, never the address)', $res['dup_users']['total'] === $baseDupUsers + 1 && !str_contains(json_encode($res['dup_users']), "$tag.same@"));

// ---- 7. isolation: created recursive item, created item elsewhere, created user with a profile outside the target
$gRec = (new Group())->add(['name' => "$tag Recursive", 'entities_id' => $target, 'is_recursive' => 1]);
$mk('group', 'g-rec', 'Group', $gRec);
$cu = $u->add(['name' => "$tag.created"]);
$mk('user', 'u-created', 'User', $cu);
$DB->delete('glpi_profiles_users', ['users_id' => $cu]);
(new Profile_User())->add(['users_id' => $cu, 'profiles_id' => 1, 'entities_id' => $other, 'is_recursive' => 0]);
$res = (new Verifier())->run($sid);
$iso = implode("\n", $res['isolation']['findings']);
chk('isolation: a created recursive item is found', str_contains($iso, "Group #$gRec") && str_contains($iso, 'is recursive'), $iso);
chk('isolation: a created item in another entity is found', str_contains($iso, "Ticket #$tMoved") && str_contains($iso, "not the target $target"));
chk('isolation: a created user with a profile outside the target is found', str_contains($iso, "created user #$cu holds 1 profile"));

// ---- 8. totals and exit code logic
chk('total() adds every finding', Verifier::total($res) === array_sum(array_column($res, 'total')) && Verifier::total($res) > 0);

// ---- 9. verify never writes
$snap = fn () => [$count('glpi_tickets'), $count('glpi_groups'), $count('glpi_users'), $count('glpi_plugin_itsmmigrator_maps'), $count('glpi_documents_items')];
$a = $snap(); (new Verifier())->run($sid); (new Verifier())->run(null);
chk('verify wrote nothing', $snap() === $a);
$res2 = (new Verifier())->run($sid);
chk('verify is repeatable', json_encode($res2) === json_encode($res));
chk('a source id with no rows has no scoped findings', array_sum(array_map(fn ($k) => (new Verifier())->run(999999)[$k]['total'], $scoped)) === 0);

// ---- cleanup (staging only)
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['name' => ['LIKE', "%$tag%"]]]) as $r) { $DB->delete('glpi_documents_items', ['itemtype' => 'Ticket', 'items_id' => $r['id']]); $DB->delete('glpi_tickets', ['id' => $r['id']]); }
$DB->delete('glpi_documents', ['name' => ['LIKE', "$tag%"]]);
$DB->delete('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid]);
$DB->delete('glpi_plugin_itsmmigrator_sources', ['id' => $sid]);
$DB->delete('glpi_groups', ['name' => ['LIKE', "$tag%"]]);
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['name' => ['LIKE', "$tag%"]]]) as $r) { $DB->delete('glpi_profiles_users', ['users_id' => $r['id']]); $DB->delete('glpi_useremails', ['users_id' => $r['id']]); $DB->delete('glpi_users', ['id' => $r['id']]); }
foreach ([$target, $other, $parent] as $id) { $DB->delete('glpi_entities', ['id' => $id]); }
echo "\nleftover: tickets=" . $count('glpi_tickets', ['name' => ['LIKE', "%$tag%"]]) . " groups=" . $count('glpi_groups', ['name' => ['LIKE', "$tag%"]]) . " users=" . $count('glpi_users', ['name' => ['LIKE', "$tag%"]]) . " entities=" . $count('glpi_entities', ['name' => ['LIKE', "$tag%"]]) . " maps=" . $count('glpi_plugin_itsmmigrator_maps') . "\n";
echo "\n" . ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ($pass passed, $fail failed)\n";
