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
// a file PHP would run keeps its information but gets ONE harmless extension, .ph_p, for every variant
check('.php -> .ph_p, the rest of the name untouched', R::safeName('report.php') === 'report.ph_p' && R::safeName('a.b.php') === 'a.b.ph_p');
check('every PHP variant ends in .ph_p (one document type covers them all)', R::safeName('x.phtml') === 'x.ph_p' && R::safeName('x.php5') === 'x.ph_p' && R::safeName('x.phar') === 'x.ph_p' && R::safeName('x.phps') === 'x.ph_p' && R::safeName('x.pht') === 'x.ph_p');
check('the extension is replaced, never added to (x.phtml.ph_p could still run on a multiple-extension server)', !str_contains(R::safeName('x.phtml'), 'phtml') && R::safeName('x.phtml') === 'x.ph_p');
check('upper case is handled and the new extension is lower case', R::safeName('X.PHP') === 'X.ph_p');
check('other names are returned as they are (php inside the name, php.txt, no extension, other types)', R::safeName('php.txt') === 'php.txt' && R::safeName('x.php.txt') === 'x.php.txt' && R::safeName('phpinfo') === 'phpinfo' && R::safeName('a.json') === 'a.json' && R::safeName('photo.png') === 'photo.png');
check('wasRenamed tells it', R::wasRenamed('a.php') === true && R::wasRenamed('a.phtml') === true && R::wasRenamed('a.txt') === false);
check('the renamed file is judged by .ph_p (must be an uploadable type, .php never is)', R::refusal(R::safeName('a.phtml'), 10, 50, ['ph_p']) === null && R::refusal(R::safeName('a.php'), 10, 50, ['txt']) !== null);
$tw2 = (string) file_get_contents(ITSM_ROOT . '/src/Writer/TicketWriter.php');
check('the writer stores the renamed file, judges the renamed name, keeps the real name in the title and the comment', str_contains($tw2, '$docName = AttachmentRule::safeName($a->name)') && str_contains($tw2, 'AttachmentRule::refusal($docName') && str_contains($tw2, "'name'               => \$a->name") && str_contains($tw2, 'Original file name in the source'));

done();
