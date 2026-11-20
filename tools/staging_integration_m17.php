<?php
// M17 integration check: plugins:itsmmigrator:import-assets through the real console command and GLPI core.
// STAGING ONLY. SYNTHETIC CSV (example names) written to the system temp dir; its own entity, which it deletes (and
// the assets, dropdown values and locations it created, found by that entity) at the end. Never touches source 33 or entity 81.
// Usage: php -c <php.ini> tools/staging_integration_m17.php [path-to-glpi-root]
$root = $argv[1] ?? 'C:/glpi-staging/app13/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('staging');
$kernel->boot();
global $DB;
$_SESSION['glpiID'] = 2; $_SESSION['glpi_use_mode'] = 0; $_SESSION['glpiname'] = 'glpi'; $_SESSION['glpiis_ids_visible'] = 0;
$prof = new Profile(); $prof->getFromDB(4); $_SESSION['glpiactiveprofile'] = $prof->fields + ['id' => 4];
$_SESSION['glpiactiveentities'] = [0]; $_SESSION['glpiactive_entity'] = 0;

$pass = 0; $fail = 0;
function chk(string $l, bool $ok, string $d = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "PASS $l\n"; } else { $fail++; echo "FAIL $l $d\n"; } }
$count = fn (string $t, array $w = []) => countElementsInTable($t, $w);

$tag = 'itsmtest' . substr(md5((string) microtime(true)), 0, 6);
$entity = (new Entity())->add(['name' => "$tag-target", 'entities_id' => 0]);
$_SESSION['glpiactiveentities'] = [0, $entity];
$csv = sys_get_temp_dir() . "/$tag.csv";
file_put_contents($csv, "\xEF\xBB\xBF" . "ID;Name;Entity;Type;Model;Manufacturer;Serial number;Inventory number;Location;Status;Comments\n"
    . "1;$tag-sw1;Root;$tag-Switch;$tag-ModelX;$tag-Acme;SN-$tag-1;INV1;$tag-HQ > $tag-Floor1;In use;first\n"
    . "2;$tag-sw2;Root;$tag-Switch;$tag-ModelX;$tag-Acme;;;;;\n"
    . "3;$tag-sw3;Root;;;;;;;;\n");
$php = PHP_BINARY; $ini = php_ini_loaded_file();
$run = function (array $extra) use ($php, $ini, $root, $entity, $csv): string {
    $cmd = escapeshellarg($php) . ' -c ' . escapeshellarg((string) $ini) . ' ' . escapeshellarg($root . '/bin/console') . ' plugins:itsmmigrator:import-assets -u glpi --entity=' . $entity . ' --file=' . escapeshellarg($csv) . ' ' . implode(' ', $extra) . ' 2>&1';
    return (string) shell_exec($cmd);
};
$nets = fn () => $count('glpi_networkequipments', ['entities_id' => $entity, 'is_deleted' => 0]);

$out = $run(['--dry-run']);
chk('dry run: counts 3 to create, writes nothing', $nets() === 0 && str_contains($out, 'created  3') && str_contains($out, 'Dry run'), trim($out));
$out = $run([]);
chk('real run: 3 network devices created, none failed', $nets() === 3 && str_contains($out, 'created  3') && str_contains($out, 'failed   0'), trim($out));
$a = $DB->request(['FROM' => 'glpi_networkequipments', 'WHERE' => ['entities_id' => $entity, 'name' => "$tag-sw1"]])->current();
chk('type, model, manufacturer, serial, inventory number, status and comment carried over', $a && (string) $a['serial'] === "SN-$tag-1" && (string) $a['otherserial'] === 'INV1' && (int) $a['networkequipmenttypes_id'] > 0 && (int) $a['networkequipmentmodels_id'] > 0 && (int) $a['manufacturers_id'] > 0 && (int) $a['states_id'] > 0 && (string) $a['comment'] === 'first', json_encode($a));
$loc = (int) ($a['locations_id'] ?? 0);
$lr = $DB->request(['FROM' => 'glpi_locations', 'WHERE' => ['id' => $loc]])->current();
chk('location path "A > B" became a two-level location', $loc > 0 && $lr && (int) $lr['level'] === 2 && str_contains((string) $lr['completename'], '>'), json_encode($lr));
$sw2 = $DB->request(['FROM' => 'glpi_networkequipments', 'WHERE' => ['entities_id' => $entity, 'name' => "$tag-sw2"]])->current();
chk('same type, model and manufacturer are shared, not duplicated', $sw2 && (int) $sw2['networkequipmenttypes_id'] === (int) $a['networkequipmenttypes_id'] && (int) $sw2['manufacturers_id'] === (int) $a['manufacturers_id']);
$out = $run([]);
chk('a second run adds nothing: 3 exist, 0 created', $nets() === 3 && str_contains($out, 'exists   3') && str_contains($out, 'created  0'), trim($out));
chk('the output prints no name or serial', !str_contains($out, $tag) && !str_contains($out, 'SN-'));
$bad = (string) shell_exec(escapeshellarg($php) . ' -c ' . escapeshellarg((string) $ini) . ' ' . escapeshellarg($root . '/bin/console') . ' plugins:itsmmigrator:import-assets -u glpi --entity=0 --file=' . escapeshellarg($csv) . ' 2>&1');
chk('the root entity is refused', str_contains($bad, '--entity must be'));

// ---- cleanup (only what this tool made)
foreach ($DB->request(['SELECT' => ['id', 'networkequipmenttypes_id', 'networkequipmentmodels_id', 'manufacturers_id', 'locations_id', 'states_id'], 'FROM' => 'glpi_networkequipments', 'WHERE' => ['entities_id' => $entity]]) as $r) {
    $DB->delete('glpi_networkequipments', ['id' => $r['id']]);
}
// types, models and manufacturers are not entity-scoped: found by this run's own name tag
foreach (['glpi_networkequipmenttypes', 'glpi_networkequipmentmodels', 'glpi_manufacturers', 'glpi_states'] as $t) { $DB->delete($t, ['name' => ['LIKE', $tag . '-%']]); }
$DB->delete('glpi_locations', ['entities_id' => $entity, 'level' => 2]);
$DB->delete('glpi_locations', ['entities_id' => $entity]);
$DB->delete('glpi_entities', ['id' => $entity]);
@unlink($csv);
echo "\nleftover: devices=" . $count('glpi_networkequipments', ['entities_id' => $entity]) . " locations=" . $count('glpi_locations', ['entities_id' => $entity]) . "\n";
echo "\n" . ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ($pass passed, $fail failed)\n";
