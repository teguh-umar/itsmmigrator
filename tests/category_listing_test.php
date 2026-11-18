<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/glpi_stubs.php';
foreach (['Adapter/Dto/ExternalItem', 'Adapter/Dto/ExternalCategory', 'CategoryListing'] as $f) {
    require_once ITSM_ROOT . "/src/$f.php";
}

use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalCategory as C;
use GlpiPlugin\Itsmmigrator\CategoryListing as L;

$items = [new C('freshservice', 'x', ['Software']), new C('freshservice', 'y', ['Hardware']), new C('freshservice', 'z', ['Hardware', 'Laptop']),
    new C('freshservice', 'w', ['hardware']), new C('freshservice', 'v', ['Hardware', 'Laptop']), new C('freshservice', 'u', [' '])];
$paths = L::paths($items);
check('paths: "A > B" strings, sorted, unique, blanks dropped', $paths === ['hardware', 'Hardware', 'Hardware > Laptop', 'Software'] || $paths === ['Hardware', 'hardware', 'Hardware > Laptop', 'Software'], json_encode($paths));
check('paths: capped at MAX', count(L::paths(array_map(fn ($i) => new C('freshservice', "k$i", ["C$i"]), range(1, L::MAX + 50)))) === L::MAX);

$a = (string) file_get_contents(ITSM_ROOT . '/ajax/categories.php');
check('ajax/categories: POST only', str_contains($a, "!== 'POST'") && str_contains($a, '405'));
check('ajax/categories: logged in, config right with Update (it uses the stored key)', str_contains($a, 'Session::checkLoginUser()') && str_contains($a, 'RightDefinition::CONFIG, UPDATE'));
check('ajax/categories: reads only sources_id, never $_GET / $_REQUEST, never checkCSRF itself', substr_count($a, '$_POST') === 1 && !str_contains($a, '$_GET') && !str_contains($a, '$_REQUEST') && !str_contains($a, 'checkCSRF'));
check('ajax/categories: discards anything printed, so debug-mode warnings cannot precede the JSON', str_contains($a, 'ob_start()') && str_contains($a, 'ob_end_clean()'));
check('ajax/categories: no api key in the file', stripos($a, 'api_key') === false);
$l = (string) file_get_contents(ITSM_ROOT . '/src/CategoryListing.php');
check('listing: writes nothing and logs nothing', !preg_match('/->(insert|update|delete)\(|Toolbox::|logInFile|error_log|->log\(/', $l));
check('listing: returns paths only (no ticket, user or count of tickets)', !preg_match('/fetchTicketChildren|tickets|users/', preg_replace('#/\*.*?\*/#s', '', $l)));
$s = (string) file_get_contents(ITSM_ROOT . '/src/Source.php');
check('source form: the picker is offered only for a saved source the user may edit', str_contains($s, 'if ($ID > 0 && $canedit)') && str_contains($s, 'Save the source first'));
check('source form: the picker sends the CSRF header', str_contains($s, "'X-Glpi-Csrf-Token'"));

done();
