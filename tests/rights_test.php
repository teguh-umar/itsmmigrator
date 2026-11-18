<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/glpi_stubs.php';
load_src('RightDefinition', 'RightSeeder');

use GlpiPlugin\Itsmmigrator\RightDefinition;
use GlpiPlugin\Itsmmigrator\RightSeeder;

check('right names are plugin_itsmmigrator_*', RightDefinition::CONFIG === 'plugin_itsmmigrator_config' && RightDefinition::RUN === 'plugin_itsmmigrator_run');
check('ALL covers every right', array_keys(RightDefinition::ALL) === array_values(RightDefinition::names()));
check('config mask is READ|UPDATE', RightDefinition::ALL[RightDefinition::CONFIG] === (READ | UPDATE));

$profiles = [
    1 => ['interface' => 'central', 'config' => READ | UPDATE],   // super-admin by right
    2 => ['interface' => 'central', 'config' => READ],            // read-only admin
    3 => ['interface' => 'helpdesk', 'config' => 0],
];
$plan = RightSeeder::seed([], $profiles);
check('super-admin gets the full mask', ($plan[1] ?? null) === RightDefinition::ALL);
check('read-only profile gets nothing', !isset($plan[2]));
check('helpdesk profile gets nothing', !isset($plan[3]));
check('no profile holds config UPDATE: nobody gets anything', RightSeeder::seed([], [2 => $profiles[2]]) === []);
// Install runs on every upgrade: the grant must repeat even when no right is new.
check('grant repeats when all rights already exist', (RightSeeder::seed(array_values(RightDefinition::names()), $profiles)[1] ?? null) === RightDefinition::ALL);

done();
