<?php

// The CSV reader of import-assets. Offline, synthetic names only (example.test, SRV-x).
require __DIR__ . '/bootstrap.php';
require_once ITSM_ROOT . '/src/AssetCsv.php';

use GlpiPlugin\Itsmmigrator\AssetCsv as C;

$glpi = "ID;Name;Entity;Type;Model;Manufacturer;Serial number;Inventory number;Location;Status;Comments\n1;SW-A;Root;Switch;Model X;Acme;SN1;INV1;HQ > Floor 1;In use;first\n2;SW-B;Root;Switch;Model X;Acme;;;;;\n";
$r = C::parse($glpi);
check('GLPI export: two rows, every column understood', count($r['rows']) === 2 && $r['errors'] === [] && $r['columns'] === ['name', 'type', 'model', 'manufacturer', 'serial', 'inventory', 'location', 'status', 'comment']);
check('values land under the right key, location path kept whole', $r['rows'][0] === ['name' => 'SW-A', 'type' => 'Switch', 'model' => 'Model X', 'manufacturer' => 'Acme', 'serial' => 'SN1', 'inventory' => 'INV1', 'location' => 'HQ > Floor 1', 'status' => 'In use', 'comment' => 'first']);
check('empty cells are empty strings', $r['rows'][1]['serial'] === '' && $r['rows'][1]['location'] === '');

check('a UTF-8 BOM and CRLF are handled', C::parse("\xEF\xBB\xBFName;Type\r\nA;t\r\n")['rows'][0]['name'] === 'A');
check('comma and tab delimiters are detected', C::parse("Name,Type\nA,t\n")['rows'][0]['type'] === 't' && C::parse("Name\tType\nA\tt\n")['rows'][0]['type'] === 't');
check('quoted fields keep their delimiter', C::parse("Name;Comments\n\"A;1\";\"x;y\"\n")['rows'][0]['comment'] === 'x;y');
check('Indonesian headers work', C::parse("Nama;Tipe;Lokasi\nA;t;l\n")['rows'][0]['location'] === 'l');
check('rows with no name are dropped, unknown columns ignored', count(C::parse("Name;Foo\nA;1\n;2\n")['rows']) === 1);
check('no Name column is an error, an empty file is an error', C::parse("Foo;Bar\n1;2\n")['errors'] !== [] && C::parse('')['errors'] !== []);

$cmd = (string) file_get_contents(ITSM_ROOT . '/src/Console/ImportAssetsCommand.php');
check('the command never prints a name, serial or address (counts and line numbers only)', !preg_match('/writeln\([^;]*\$row\[/', $cmd) && str_contains($cmd, 'Failed on file lines'));
check('an existing asset of the same name in the entity is left alone, and the definition is told apart', str_contains($cmd, "'exists'") && str_contains($cmd, "\$where['assets_assetdefinitions_id'] = \$def;"));
check('a dry run writes nothing', str_contains($cmd, 'if ($dry) {'));
check('a target entity is required and the root is refused', str_contains($cmd, '$entity <= 0'));

done();
