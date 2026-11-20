<?php

// The run stops when the source refuses the key on several tickets in a row; an approval whose approver is not a GLPI user
// leaves a private note. Offline (the writer needs GLPI, so it is read from source).
require __DIR__ . '/bootstrap.php';
require_once ITSM_ROOT . '/src/Counters.php';
require_once ITSM_ROOT . '/src/CategoryFilter.php';
require_once ITSM_ROOT . '/src/DateRange.php';
require_once ITSM_ROOT . '/src/Context.php';

use GlpiPlugin\Itsmmigrator\Context;
use GlpiPlugin\Itsmmigrator\Counters;

$c = new Context(['id' => 1], [], [], new Counters(), fn (string $m) => null);
check('two key refusals in a row do not stop the run', $c->authFailed() === false && $c->authFailed() === false);
check('the third in a row does', $c->authFailed() === true);
$c = new Context(['id' => 1], [], [], new Counters(), fn (string $m) => null);
$c->authFailed(); $c->authFailed(); $c->authOk();
check('a ticket that went through resets the streak', $c->authFailed() === false && $c->authFailed() === false && $c->authFailed() === true);

$tw = (string) file_get_contents(ITSM_ROOT . '/src/Writer/TicketWriter.php');
check('only 401 and 403 from the source count, anything else is an ordinary failed ticket', str_contains($tw, 'in_array($e->status, [401, 403], true) && $ctx->authFailed()'));
check('a ticket that went through calls authOk', str_contains($tw, '$ctx->authOk();'));
check('the stop says the key is the problem and how to go on', str_contains($tw, 'Nothing is wrong with the tickets') && str_contains($tw, 'retry-failed'));
check('unmapped approver: private followup, mapped as skipped, never the fallback user', str_contains($tw, 'noteUnmappedApproval') && str_contains($tw, "'is_private' => 1") && str_contains($tw, 'MapRepository::markSkipped($rowId, \'approver is not a GLPI user'));
check('a second run does not add the note twice (mapped skipped is honoured)', str_contains($tw, "in_array(\$row['status'], [MapRepository::OK, MapRepository::SKIPPED], true)"));
check('the note escapes names and remark and says level and decision', str_contains($tw, 'htmlspecialchars($who') && str_contains($tw, 'Approval (source), level ') && str_contains($tw, 'Remark: '));

done();
