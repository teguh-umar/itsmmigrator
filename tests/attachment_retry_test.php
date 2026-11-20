<?php

// "Fetch again the attachments GLPI refused": wiring and rules, read from source (the writer needs GLPI). The real
// behaviour is covered by tools/staging_integration_m16.php.
require __DIR__ . '/bootstrap.php';

$tw  = (string) file_get_contents(ITSM_ROOT . '/src/Writer/TicketWriter.php');
$cli = (string) file_get_contents(ITSM_ROOT . '/src/Console/RunCommand.php');
$web = (string) file_get_contents(ITSM_ROOT . '/src/WebRun.php');
$ajx = (string) file_get_contents(ITSM_ROOT . '/ajax/run.php');
$vw  = (string) file_get_contents(ITSM_ROOT . '/src/RunView.php');

check('CLI flag --retry-skipped-attachments reaches the run as retry_attachments', str_contains($cli, "'retry-skipped-attachments'") && str_contains($cli, "'retry_attachments'      => \$input->getOption('retry-skipped-attachments') ? true : null"));
check('web: the flag is whitelisted (ajax), typed bool (WebRun) and has a checkbox', str_contains($ajx, "'retry_attachments'") && str_contains($web, "'retry_attachments' => 'bool'") && str_contains($vw, "check('retry_attachments'"));
check('only an already imported ticket (map ok) is read again, and only when the flag is on', str_contains($tw, "\$ctx->flag('retry_attachments') && \$existing !== null && (string) \$existing['status'] === MapRepository::OK"));
check('only tickets with a skipped document row are read again (no read for the others)', str_contains($tw, "'status' => MapRepository::SKIPPED, 'itemtype' => 'Ticket', 'items_id' => \$ticketId") && str_contains($tw, 'if ($skipped === []) {'));
check('without the flag a skipped document stays final; with it, only a skipped one is retried', str_contains($tw, "(\$row['status'] === MapRepository::SKIPPED && !\$again)"));
check('still refused: no second note, only the reason is updated', str_contains($tw, '$again ? $this->stillRefused(') && str_contains($tw, 'none is added'));
check('imported at last: the earlier note is replaced, not duplicated', str_contains($tw, "'%Attachment not imported:%'") && str_contains($tw, 'Attachment imported later:') && str_contains($tw, "\$DB->update('glpi_itilfollowups', ['content' => \$new]"));
check('a dry run counts and writes nothing', str_contains($tw, "'would_retry'"));
check('a failure while fetching again never fails the ticket (logged)', str_contains($tw, 'fetching the skipped attachments again failed'));

done();
