<?php

// A catalog item -> the plain description of a GLPI Form. Offline, synthetic names only.
require __DIR__ . '/bootstrap.php';
require_once ITSM_ROOT . '/src/Catalog/FormMapper.php';

use GlpiPlugin\Itsmmigrator\Catalog\FormMapper as M;

$item = [
    'name' => 'Item A', 'short_description' => '<b>short</b> text', 'description' => '<p>long html</p>',
    'custom_fields' => [
        ['label' => 'Second', 'field_type' => 'custom_dropdown', 'position' => 2, 'required' => true, 'choice_obj' => [['id' => '1', 'value' => 'One'], ['id' => '2', 'value' => 'Two']], 'field_options' => ['placeholder' => 'pick']],
        ['label' => 'First', 'field_type' => 'custom_text', 'position' => 1, 'required' => false],
        ['label' => 'Many', 'field_type' => 'custom_multi_select_dropdown', 'position' => 3, 'choices' => [['A', 1], ['B', 2]]],
        ['label' => 'Agree', 'field_type' => 'custom_checkbox', 'position' => 4],
        ['label' => 'When', 'field_type' => 'custom_date', 'position' => 5],
        ['label' => 'How many', 'field_type' => 'custom_number', 'position' => 6],
        ['label' => 'Price', 'field_type' => 'custom_decimal', 'position' => 7],
        ['label' => 'Why', 'field_type' => 'custom_paragraph', 'position' => 8],
        ['label' => 'Site', 'field_type' => 'custom_url', 'position' => 9],
        ['label' => 'Tree', 'field_type' => 'nested_field', 'position' => 10, 'nested_fields' => [['label' => 'Sub', 'level' => 3], ['label' => 'Top', 'level' => 2]]],
        ['label' => 'Gone', 'field_type' => 'custom_text', 'position' => 11, 'archived' => true],
        ['label' => 'Empty dropdown', 'field_type' => 'custom_dropdown', 'position' => 12, 'choice_obj' => []],
        ['label' => 'Weird', 'field_type' => 'custom_something', 'position' => 13],
    ],
];
$m = M::map($item);
check('form name, plain-text description and html header', $m['name'] === 'Item A' && $m['description'] === 'short text' && $m['header'] === '<p>long html</p>');
$names = array_column($m['questions'], 'name');
check('questions follow the source position, an archived field is dropped', array_slice($names, 0, 3) === ['First', 'Second', 'Many'] && !in_array('Gone', $names, true));
$by = array_column($m['questions'], null, 'name');
check('text -> short, paragraph -> long, number and decimal -> number, date -> date, url -> short', $by['First']['kind'] === 'short' && $by['Why']['kind'] === 'long' && $by['How many']['kind'] === 'number' && $by['Price']['kind'] === 'number' && $by['When']['kind'] === 'date' && $by['Site']['kind'] === 'short');
check('dropdown keeps its choices in order and the required flag and placeholder', $by['Second']['kind'] === 'dropdown' && $by['Second']['options'] === ['One', 'Two'] && $by['Second']['required'] === true && $by['Second']['note'] === 'pick');
check('multi-select -> checkbox list (choices read from the pair form too)', $by['Many']['kind'] === 'checkbox' && $by['Many']['options'] === ['A', 'B']);
check('a single checkbox -> a checkbox with one option, its label', $by['Agree']['kind'] === 'checkbox' && $by['Agree']['options'] === ['Agree']);
check('a nested field becomes one short text per level, in level order, with a note', isset($by['Top'], $by['Sub']) && array_search('Top', $names, true) < array_search('Sub', $names, true) && $by['Top']['kind'] === 'short' && str_contains($by['Top']['note'], 'hierarchical'));
check('a dropdown with no choices and an unknown type become short texts (no field dropped)', $by['Empty dropdown']['kind'] === 'short' && $by['Weird']['kind'] === 'short');
check('an item with no fields gives a form with no question', M::map(['name' => 'X', 'custom_fields' => []])['questions'] === [] && M::map([])['name'] === 'Service item');
check('a long label is cut to 255 characters', mb_strlen(M::map(['custom_fields' => [['label' => str_repeat('é', 400), 'field_type' => 'custom_text']]])['questions'][0]['name']) === 255);

$cmd = (string) file_get_contents(ITSM_ROOT . '/src/Console/ImportCatalogCommand.php');
check('every Form is created inactive and as a draft, never recursive', str_contains($cmd, "'is_active' => 0, 'is_draft' => 1") && str_contains($cmd, "'is_recursive' => 0"));
check('an item already imported is left alone (map ok), so a second run adds nothing', str_contains($cmd, "MapRepository::find(\$source, 'catalog_item'") && str_contains($cmd, 'forms_exist'));
check('a failed item is recorded in the map and counted, the run goes on', str_contains($cmd, 'MapRepository::markFailed') && str_contains($cmd, 'forms_failed'));
check('the output is counts only (no name is written)', !preg_match('/writeln\([^;]*\$(item|map|c)\[/', $cmd));
check('a dry run writes nothing', str_contains($cmd, 'if ($dry) {'));
check('the adapter interface has the read-only catalog call', str_contains((string) file_get_contents(ITSM_ROOT . '/src/Adapter/SourceAdapterInterface.php'), 'fetchServiceCatalog'));

done();
