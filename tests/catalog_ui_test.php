<?php

// The Run page's catalog button: the endpoint, its parameters and the no-duplicate guarantees. Offline, read from source
// (the real behaviour, including a run that died half way, is checked by tools/staging_integration_m18.php).
require __DIR__ . '/bootstrap.php';

$ajx = (string) file_get_contents(ITSM_ROOT . '/ajax/catalog.php');
$imp = (string) file_get_contents(ITSM_ROOT . '/src/Catalog/CatalogImporter.php');
$vw  = (string) file_get_contents(ITSM_ROOT . '/src/RunView.php');
$cli = (string) file_get_contents(ITSM_ROOT . '/src/Console/ImportCatalogCommand.php');

check('POST only, logged in, and the same right as the Run page', str_contains($ajx, "'POST'") && str_contains($ajx, 'Session::checkLoginUser();') && str_contains($ajx, 'RightDefinition::RUN, UPDATE'));
check('the only things read from the request are action, sources_id and token', preg_match_all('/\$_POST\[\'(\w+)\'\]/', $ajx, $m) && array_values(array_unique($m[1])) === ['action', 'sources_id', 'token']);
check('nothing but plain ids and a 32-hex token: no URL, path, JSON, HTML or SQL comes in', str_contains($ajx, "'/^[a-f0-9]{32}\$/'") && !preg_match('/\$_(GET|REQUEST|COOKIE|SERVER\[.HTTP)/', $ajx) && !str_contains($ajx, 'php://input'));
check('count writes nothing (dry), start and step make a batch of BATCH forms', str_contains($ajx, 'process($src, $cat, true, null)') && substr_count($ajx, 'CatalogImporter::BATCH') === 2);
check('a lost or invalid token is an answer (410), not a crash', str_contains($ajx, '410'));
check('the page has both buttons, a confirmation before writing, and says Forms are inactive', str_contains($vw, 'itsm-cat-count') && str_contains($vw, 'itsm-cat-run') && str_contains($vw, 'window.confirm') && str_contains($vw, 'inactive'));
check('both buttons are disabled while one runs (no double click), and a busy answer is explained', str_contains($vw, 'function lock(on)') && str_contains($vw, 'r.busy'));
check('the CSRF token is sent in the header, as for the Run page', str_contains($vw, "'X-Glpi-Csrf-Token': token()"));

check('one batch at a time per source (database lock), a second one gets busy and does nothing', str_contains($imp, 'GET_LOCK(') && str_contains($imp, 'RELEASE_LOCK(') && str_contains($imp, "'busy' => true"));
check('an item already imported (map row ok) is never created again', str_contains($imp, "MapRepository::find(\$source, 'catalog_item', \$key)") && str_contains($imp, "'forms_exist'"));
check('the Form id is written to the map row at once, and a half-made Form of a dead run is removed and made again', str_contains($imp, "'items_id' => \$formId], ['id' => \$mapRowId]") && str_contains($imp, 'removed and made again') && str_contains($imp, "->delete(['id' => (int) \$mr['items_id']], true)"));
check('forms are never flagged is_draft (GLPI hides and purges those)', str_contains($imp, "'is_active' => 0, 'is_draft' => 0") && !str_contains($imp, "'is_draft' => 1"));
check('the console command uses the same class, so both ways behave the same', str_contains($cli, 'CatalogImporter::process('));

done();
