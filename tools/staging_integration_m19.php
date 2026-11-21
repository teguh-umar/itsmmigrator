<?php
// M19 integration check: the monitor-ticket asset link and the replacement of its stale note (AssetLinker, rc32) in real GLPI.
// STAGING ONLY. SYNTHETIC tickets and assets (example names) in its own entity; deletes only by the ids it made (journalled).
// Run it only when no run is active. It never touches source 33 or entity 81.
// Usage: php -c <php.ini> tools/staging_integration_m19.php [path-to-glpi-root]
$root = $argv[1] ?? 'C:/glpi-staging/app13/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('staging');
$kernel->boot();
global $DB;
$_SESSION['glpiID'] = 2; $_SESSION['glpi_use_mode'] = 0; $_SESSION['glpiname'] = 'glpi'; $_SESSION['glpiis_ids_visible'] = 0;
$prof = new Profile(); $prof->getFromDB(4); $_SESSION['glpiactiveprofile'] = $prof->fields + ['id' => 4];
$_SESSION['glpiactiveentities'] = [0]; $_SESSION['glpiactive_entity'] = 0;

use GlpiPlugin\Itsmmigrator\AssetLinker;

$pass = 0; $fail = 0;
function chk(string $l, bool $ok, string $d = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "PASS $l\n"; } else { $fail++; echo "FAIL $l $d\n"; } }
$count = fn (string $t, array $w = []) => countElementsInTable($t, $w);
if ($DB->request(['FROM' => 'glpi_plugin_itsmmigrator_runs', 'WHERE' => ['status' => 'running']])->current() !== null) { echo "A run is active: not running.\n"; exit(2); }

$journalFile = sys_get_temp_dir() . '/itsm_m19_ids.json';
$journal = ['entity' => 0];
$purge = function (array $j) use ($DB) {
    $e = (int) ($j['entity'] ?? 0);
    if ($e <= 0) { return; }
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $e]]) as $t) {
        foreach (['glpi_itilfollowups' => ['itemtype' => 'Ticket', 'items_id' => $t['id']], 'glpi_items_tickets' => ['tickets_id' => $t['id']], 'glpi_tickets_users' => ['tickets_id' => $t['id']]] as $tb => $w) { $DB->delete($tb, $w); }
        $DB->delete('glpi_tickets', ['id' => $t['id']]);
    }
    $DB->delete('glpi_networkequipments', ['entities_id' => $e]);
    $DB->delete('glpi_entities', ['id' => $e]);
};
if (is_file($journalFile)) { $old = json_decode((string) file_get_contents($journalFile), true); if (is_array($old)) { $purge($old); echo "cleaned up a crashed earlier run by its journal\n"; } @unlink($journalFile); }

$tag = 'itsmtest' . substr(md5((string) microtime(true)), 0, 6);
$entity = (new Entity())->add(['name' => "$tag-target", 'entities_id' => 0]);
$journal['entity'] = $entity; file_put_contents($journalFile, json_encode($journal));
$_SESSION['glpiactiveentities'] = [0, $entity];

$mkTicket = function (string $device) use ($entity, $tag): int {
    $body = "<p>Title NMS - alarm layanan $device - 192.0.2.77 Termonitor Down Message Device Down Device: $device 192.0.2.77 Category: Server Error Condition: Critical</p>";
    return (int) (new Ticket())->add(['name' => "[FS#$tag] NMS - alarm $device", 'content' => $body, 'entities_id' => $entity, '_skip_default_actor' => true, '_disablenotif' => true]);
};
$notes  = fn (int $t, string $like) => $count('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $t, 'is_private' => 1, ['content' => ['LIKE', $like]]]);
$links  = fn (int $t) => $count('glpi_items_tickets', ['tickets_id' => $t]);
$name   = "$tag-sw-core";
$t1 = $mkTicket($name);
chk('a monitor ticket was created for the test', $t1 > 0);

// ---- 1. no asset yet: one private "no asset" note, nothing linked, a second pass adds no second note
$r = AssetLinker::apply($t1);
chk('no asset: result missing, one stale note "no asset", no link', $r === 'missing' && $notes($t1, '%no asset with that name%') === 1 && $links($t1) === 0, $r);
$r = AssetLinker::apply($t1);
chk('second pass: still one note (never two)', $r === 'missing' && $notes($t1, '%Asset (monitor):%') === 1);

// ---- 2. the asset appears: linked, the stale note is REPLACED (not deleted, not doubled)
$a1 = (int) (new NetworkEquipment())->add(['name' => $name, 'entities_id' => $entity]);
$r = AssetLinker::apply($t1, true);
chk('dry run with the asset present: says linked but writes nothing (no link, note still stale)', $r === 'linked' && $links($t1) === 0 && $notes($t1, '%no asset with that name%') === 1, $r);
$r = AssetLinker::apply($t1);
chk('asset exists: linked, one link', $r === 'linked' && $links($t1) === 1, $r);
chk('the stale "no asset" note is gone, replaced by ONE "now linked" note (history kept, nothing deleted)', $notes($t1, '%no asset with that name%') === 0 && $notes($t1, '%now linked to the GLPI asset%') === 1 && $notes($t1, '%Asset (monitor):%') === 1);
$r = AssetLinker::apply($t1);
chk('a further pass: already linked, still one link and one note', $r === 'already' && $links($t1) === 1 && $notes($t1, '%Asset (monitor):%') === 1, $r);

// ---- 3. two assets with the same name: "more than one" note, no link; later one is removed: linked and the note replaced
$name2 = "$tag-ap-dup";
$t2 = $mkTicket($name2);
$b1 = (int) (new NetworkEquipment())->add(['name' => $name2, 'entities_id' => $entity]);
$b2 = (int) (new NetworkEquipment())->add(['name' => $name2, 'entities_id' => $entity]);
$r = AssetLinker::apply($t2);
chk('two assets of that name: ambiguous, a "more than one" note, no link', $r === 'ambiguous' && $notes($t2, '%more than one asset%') === 1 && $links($t2) === 0, $r);
$DB->delete('glpi_networkequipments', ['id' => $b2]);
$r = AssetLinker::apply($t2);
chk('one removed: linked and the "more than one" note replaced', $r === 'linked' && $links($t2) === 1 && $notes($t2, '%more than one asset%') === 0 && $notes($t2, '%now linked%') === 1, $r);

// ---- 4. a ticket that is not a monitor ticket is left alone
$tp = (int) (new Ticket())->add(['name' => "$tag plain", 'content' => '<p>a laptop problem</p>', 'entities_id' => $entity, '_skip_default_actor' => true, '_disablenotif' => true]);
chk('a plain ticket: none, no note, no link', AssetLinker::apply($tp) === 'none' && $notes($tp, '%Asset (monitor):%') === 0 && $links($tp) === 0);

// ---- cleanup (only what this tool made)
$purge($journal);
@unlink($journalFile);
echo "\nleftover: tickets=" . $count('glpi_tickets', ['entities_id' => $entity]) . " assets=" . $count('glpi_networkequipments', ['entities_id' => $entity]) . " entity=" . $count('glpi_entities', ['id' => $entity]) . "\n";
echo "\n" . ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ($pass passed, $fail failed)\n";
