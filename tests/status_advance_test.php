<?php

require __DIR__ . '/bootstrap.php';
require_once ITSM_ROOT . '/src/Mapping.php';

use GlpiPlugin\Itsmmigrator\Mapping as M;

// ---- the import rule: a status only moves forward, only while open or pending
check('Pending -> Approval advances', M::advances(4, 10));
check('Pending -> Solved advances', M::advances(4, 5));
check('Pending -> Closed advances', M::advances(4, 6));
check('New -> Pending advances', M::advances(1, 4));
check('Assigned -> Approval advances', M::advances(2, 10));
check('Approval -> Solved advances', M::advances(10, 5));
check('Approval -> Pending does NOT (backwards)', !M::advances(10, 4));
check('Pending -> Assigned does NOT (backwards)', !M::advances(4, 2));
check('same status does NOT', !M::advances(4, 4) && !M::advances(10, 10));
check('Solved never moves, not even to Closed', !M::advances(5, 6) && !M::advances(5, 4) && !M::advances(5, 1));
check('Closed never reopens', !M::advances(6, 1) && !M::advances(6, 4) && !M::advances(6, 5));
check('an unknown status never moves', !M::advances(99, 5) && !M::advances(4, 99) && !M::advances(0, 0));

// ---- the status "Approval" is a known, named status
check('Approval is 10 and named', M::APPROVAL === 10 && M::statusName(10) === 'Approval');
check('names for the other statuses', M::statusName(4) === 'Pending' && M::statusName(5) === 'Solved' && M::statusName(6) === 'Closed');
check('a custom map can send "Waiting on Approval" to Approval', M::status('Waiting on Approval', true, ['Waiting on Approval' => 10]) === ['value' => 10, 'known' => true]);

done();
