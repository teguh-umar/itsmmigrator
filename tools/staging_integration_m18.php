<?php
// M18 integration check: plugins:itsmmigrator:import-catalog (service catalog -> inactive draft GLPI Forms) through the real command, mapper and GLPI Forms.
// STAGING ONLY. A SYNTHETIC Freshservice (fake HTTP transport): a catalog of 2 categories and 2 items. Its own entity and source;
// deletes only by those ids. Run it only when no run is active.
// It never touches source 33 or entity 81.
// Usage: php -c <php.ini> tools/staging_integration_m18.php [path-to-glpi-root]
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
$journalFile = sys_get_temp_dir() . '/itsm_m18_ids.json';
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



use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use GlpiPlugin\Itsmmigrator\Console\ImportCatalogCommand;

$journal['limit'] = null; $save();
$transport = function (string $url, array $h, ?string $sink) use ($tag): array {
    $ok = fn (array $b) => ['status' => 200, 'headers' => [], 'body' => json_encode($b)];
    if (str_contains($url, '/service_catalog/categories')) {
        return $ok(['service_categories' => [['id' => 9001, 'name' => "$tag-Parent", 'parent_id' => null], ['id' => 9002, 'name' => "$tag-Child", 'parent_id' => 9001]]]);
    }
    if (preg_match('#/service_catalog/items\?#', $url)) {
        return $ok(['service_items' => [['display_id' => 9100001, 'name' => "$tag-Item1", 'category_id' => 9002, 'deleted' => false], ['display_id' => 9100002, 'name' => "$tag-Item2", 'category_id' => 9001, 'deleted' => false]]]);
    }
    if (preg_match('#/service_catalog/items/9100001$#', $url)) {
        return $ok(['service_item' => ['display_id' => 9100001, 'name' => "$tag-Item1", 'short_description' => 'short', 'description' => '<p>long</p>', 'category_id' => 9002, 'custom_fields' => [
            ['label' => 'Reason', 'field_type' => 'custom_text', 'position' => 1, 'required' => true],
            ['label' => 'Kind', 'field_type' => 'custom_dropdown', 'position' => 2, 'required' => false, 'choice_obj' => [['id' => '1', 'value' => 'One'], ['id' => '2', 'value' => 'Two']]],
            ['label' => 'Many', 'field_type' => 'custom_multi_select_dropdown', 'position' => 3, 'choice_obj' => [['id' => '1', 'value' => 'A'], ['id' => '2', 'value' => 'B']]],
            ['label' => 'When', 'field_type' => 'custom_date', 'position' => 4, 'required' => true],
            ['label' => 'Why', 'field_type' => 'custom_paragraph', 'position' => 5],
            ['label' => 'How many', 'field_type' => 'custom_number', 'position' => 6],
            ['label' => 'Tree', 'field_type' => 'nested_field', 'position' => 7, 'nested_fields' => [['label' => 'Top', 'level' => 2], ['label' => 'Sub', 'level' => 3]]],
        ]]]);
    }
    if (preg_match('#/service_catalog/items/9100002$#', $url)) {
        return $ok(['service_item' => ['display_id' => 9100002, 'name' => "$tag-Item2", 'category_id' => 9001, 'custom_fields' => []]]);
    }
    return ['status' => 404, 'headers' => [], 'body' => '{}'];
};
AdapterFactory::setOverride(fn (array $s, string $k) => new FreshserviceAdapter(new FreshserviceClient('https://fs.example.test', 'k', $transport, fn (float $s2) => null, 0.0), 1));
// the session is set above by hand (a CLI session cannot be regenerated in-process): the command's own session loading is a no-op here
class TestImportCatalogCommand extends ImportCatalogCommand { protected function loadUserSession(string $username): void {} }
$app = new Application(); $cmd = new TestImportCatalogCommand(); $app->add($cmd);
$exec = function (array $o) use ($cmd, $sid): array { $t = new CommandTester($cmd); $code = $t->execute(['--username' => 'glpi', '--source' => $sid] + $o); return [$code, $t->getDisplay()]; };
$forms = fn () => iterator_to_array($DB->request(['FROM' => 'glpi_forms_forms', 'WHERE' => ['entities_id' => $entity], 'ORDER' => 'id']), false);

[$c, $out] = $exec(['--dry-run' => true]);
chk('dry run: 2 forms and 8 questions counted, nothing written', $c === 0 && $forms() === [] && preg_match('/forms\s+2/', $out) && preg_match('/questions\s+8/', $out) && str_contains($out, 'Dry run'), trim($out));

[$c, $out] = $exec([]);
$f = $forms();
chk('real run: 2 forms, 2 categories, 8 questions, none failed', $c === 0 && count($f) === 2 && preg_match('/categories\s+2/', $out) && preg_match('/forms\s+2/', $out) && preg_match('/questions\s+8/', $out) && preg_match('/forms_failed\s+0/', $out), trim($out));
chk('every form is INACTIVE and NOT a GLPI draft (drafts are hidden and purged), in the source entity, not recursive', count($f) === 2 && !array_filter($f, fn ($x) => (int) $x['is_active'] !== 0 || (int) $x['is_draft'] !== 0 || (int) $x['entities_id'] !== $entity || (int) $x['is_recursive'] !== 0));
$listed = $count('glpi_forms_forms', ['entities_id' => $entity, 'is_deleted' => 0, 'is_draft' => 0]);
chk('the forms show in the Forms list (the list shows non-draft forms)', $listed === 2, 'listed=' . $listed);
$f1 = $f[0] ?? null;
chk('name, description and html header carried over', $f1 && $f1['name'] === "$tag-Item1" && $f1['description'] === 'short' && str_contains((string) $f1['header'], 'long'), json_encode($f1 ? [$f1['name'], $f1['description']] : null));
$qs = $f1 ? iterator_to_array($DB->request(['SELECT' => ['q.*'], 'FROM' => 'glpi_forms_questions AS q', 'INNER JOIN' => ['glpi_forms_sections AS s' => ['ON' => ['q' => 'forms_sections_id', 's' => 'id']]], 'WHERE' => ['s.forms_forms_id' => $f1['id']], 'ORDER' => 'q.vertical_rank']), false) : [];
$types = array_map(fn ($q) => substr((string) strrchr((string) $q['type'], '\\'), 1), $qs);
chk('question types in source order', $types === ['QuestionTypeShortText', 'QuestionTypeDropdown', 'QuestionTypeCheckbox', 'QuestionTypeDateTime', 'QuestionTypeLongText', 'QuestionTypeNumber', 'QuestionTypeShortText', 'QuestionTypeShortText'], json_encode($types));
chk('required flags kept (only on the two required questions)', array_map(fn ($q) => (int) $q['is_mandatory'], $qs) === [1, 0, 0, 1, 0, 0, 0, 0], json_encode(array_column($qs, 'is_mandatory')));
$dd = json_decode((string) ($qs[1]['extra_data'] ?? ''), true);
chk('dropdown options carried in order', is_array($dd) && array_values($dd['options'] ?? []) === ['One', 'Two'] && ($dd['is_multiple_dropdown'] ?? null) === false, (string) ($qs[1]['extra_data'] ?? ''));
chk('a nested field became one short text per level, in level order', ($qs[6]['name'] ?? '') === 'Top' && ($qs[7]['name'] ?? '') === 'Sub');
chk('the default destination (ticket) was created with each form', $count('glpi_forms_destinations_formdestinations', ['forms_forms_id' => array_column($f, 'id')]) >= 2);
$cats = iterator_to_array($DB->request(['FROM' => 'glpi_forms_categories', 'WHERE' => ['name' => ["$tag-Parent", "$tag-Child"]], 'ORDER' => 'level']), false);
chk('category tree created: the child sits under the parent', count($cats) === 2 && (int) $cats[1]['forms_categories_id'] === (int) $cats[0]['id'] && (int) $f1['forms_categories_id'] === (int) $cats[1]['id']);
chk('the output prints no name', !str_contains($out, $tag));

// ---- a form can be edited and saved by an administrator even with required questions left empty
$upd = (new \Glpi\Form\Form());
$upd->getFromDB((int) $f1['id']);
chk('an administrator can save the draft form (required questions are about answering, not editing)', $upd->update(['id' => (int) $f1['id'], 'name' => "$tag-Item1 edited"]) === true);

// ---- second run: nothing new
[$c, $out] = $exec([]);
chk('second run adds nothing: 2 exist, 0 created', count($forms()) === 2 && preg_match('/forms_exist\s+2/', $out) && preg_match('/forms\s+0/', $out), trim($out));

// ---- batch by batch (what the Run page does): one form per request, no duplicate, the same result
foreach ($forms() as $x) { (new \Glpi\Form\Form())->delete(['id' => (int) $x['id']], true); }
$DB->delete('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid, 'src_type' => ['catalog_item', 'catalog_category']]);
$DB->delete('glpi_forms_categories', ['name' => ["$tag-Child"]]); $DB->delete('glpi_forms_categories', ['name' => ["$tag-Parent"]]);
$srcObj = new \GlpiPlugin\Itsmmigrator\Source(); $srcObj->getFromDB($sid);
$prep = \GlpiPlugin\Itsmmigrator\Catalog\CatalogImporter::prepare($srcObj);
$cat  = \GlpiPlugin\Itsmmigrator\Catalog\CatalogImporter::load($prep['token']);
chk('the catalog is kept for the next batches (token file)', is_array($cat) && count($cat['items']) === 2);
$steps = 0; $done = false; $createdTotal = 0;
while (!$done && $steps < 10) { $r = \GlpiPlugin\Itsmmigrator\Catalog\CatalogImporter::process($srcObj, $cat, false, 1); $done = $r['done']; $createdTotal += $r['counts']['forms']; $steps++; }
chk('batch of 1: two batches make the 2 forms, the last one says done', count($forms()) === 2 && $createdTotal === 2 && $done && $steps === 2, "steps=$steps created=$createdTotal");
$r = \GlpiPlugin\Itsmmigrator\Catalog\CatalogImporter::process($srcObj, $cat, false, 1);
chk('another click after the end creates nothing', count($forms()) === 2 && $r['counts']['forms'] === 0 && $r['done']);
// a run that died half way: the Form exists (id already in the map row) but the item is not ok
$victim = $DB->request(['FROM' => 'glpi_plugin_itsmmigrator_maps', 'WHERE' => ['sources_id' => $sid, 'src_type' => 'catalog_item', 'src_id' => '9100001']])->current();
$oldId = (int) $victim['items_id'];
$DB->update('glpi_plugin_itsmmigrator_maps', ['status' => 'in_progress'], ['id' => (int) $victim['id']]);
$r = \GlpiPlugin\Itsmmigrator\Catalog\CatalogImporter::process($srcObj, $cat, false, 8);
$nowRow = $DB->request(['FROM' => 'glpi_plugin_itsmmigrator_maps', 'WHERE' => ['id' => (int) $victim['id']]])->current();
chk('a half-made form of a dead run is removed and made again: still 2 forms, none doubled, item ok', count($forms()) === 2 && (string) $nowRow['status'] === 'ok' && (int) $nowRow['items_id'] > 0 && $count('glpi_forms_forms', ['id' => $oldId]) === 0, 'forms=' . count($forms()));
chk('and it has all its questions again', $count('glpi_forms_questions', ['forms_sections_id' => array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_forms_sections', 'WHERE' => ['forms_forms_id' => (int) $nowRow['items_id']]]), false), 'id')]) === 8);
\GlpiPlugin\Itsmmigrator\Catalog\CatalogImporter::forget($prep['token']);
chk('the kept catalog file is removed at the end', \GlpiPlugin\Itsmmigrator\Catalog\CatalogImporter::load($prep['token']) === null);

// ---- cleanup (only what this tool made)
AdapterFactory::setOverride(null);
foreach ($forms() as $x) { (new \Glpi\Form\Form())->delete(['id' => (int) $x['id']], true); }
$DB->delete('glpi_forms_categories', ['name' => ["$tag-Child"]]);
$DB->delete('glpi_forms_categories', ['name' => ["$tag-Parent"]]);
$DB->delete('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid]);
$DB->delete('glpi_plugin_itsmmigrator_runs', ['sources_id' => $sid]);
$DB->delete('glpi_plugin_itsmmigrator_sources', ['id' => $sid]);
$DB->delete('glpi_entities', ['id' => $entity]);
@unlink($journalFile);
echo "\nleftover: forms=" . $count('glpi_forms_forms', ['entities_id' => $entity]) . " categories=" . $count('glpi_forms_categories', ['name' => ["$tag-Parent", "$tag-Child"]]) . " maps=" . $count('glpi_plugin_itsmmigrator_maps', ['sources_id' => $sid]) . "\n";
echo "\n" . ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ($pass passed, $fail failed)\n";
