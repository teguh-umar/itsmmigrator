<?php

// Would GLPI take the file? Offline.
require __DIR__ . '/bootstrap.php';
require_once ITSM_ROOT . '/src/AttachmentRule.php';

use GlpiPlugin\Itsmmigrator\AttachmentRule as R;

$ok = ['zip', 'pdf', 'xlsx', 'png'];
check('a small allowed type is taken', R::refusal('a.pdf', 300000, 2, $ok) === null);
check('over the size limit is refused, with the limit and the size', str_contains((string) R::refusal('a.zip', 2260252, 2, $ok), 'GLPI limit of 2 MB') && str_contains((string) R::refusal('a.zip', 12457753, 2, $ok), '11.9 MB'));
check('exactly the limit is taken', R::refusal('a.zip', 2 * 1048576, 2, $ok) === null);
check('an unknown type is refused, named by its extension', R::refusal('data.JSON', 1000, 2, $ok) === 'the file type .json is not an allowed GLPI document type');
check('no extension is refused', str_contains((string) R::refusal('README', 10, 2, $ok), 'no file extension'));
check('a limit of 0 means no size limit', R::refusal('a.zip', 99999999, 0, $ok) === null);
check('the extension check is case-insensitive', R::refusal('A.PDF', 1000, 2, $ok) === null);
check('size wording', R::size(500) === '1 KB' && R::size(2048) === '2 KB' && R::size(3145728) === '3.0 MB');

$tw = (string) file_get_contents(ITSM_ROOT . '/src/Writer/TicketWriter.php');
check('writer: a refused file becomes a private followup and a skipped map, never a failed ticket', str_contains($tw, 'AttachmentRule::refusal(') && str_contains($tw, 'markSkipped($rowId, $why)') && str_contains($tw, "'is_private' => 1") && !str_contains($tw, "throw new \RuntimeException('GLPI refused the document')"));
done();
