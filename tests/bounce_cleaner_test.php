<?php

require __DIR__ . '/bootstrap.php';
require_once ITSM_ROOT . '/src/BounceCleaner.php';

use GlpiPlugin\Itsmmigrator\BounceCleaner as B;

// SYNTHETIC bounce notice (example names only), shaped like the real ones.
$junk = str_repeat('1.84300:01000000, ', 400);
$bounce = '<p>Requester (source): Postmaster &lt;postmaster@example.test&gt;</p>'
    . '<p>CAUTION: This email originated from outside of the organization. Do not click links.</p>'
    . '<p>----- Original message -----<br>From:&lt;postmaster@example.test&gt;<br>To:&lt;desk@example.org&gt;<br>Date:Sat, 28 Mar 2026 09:07:58 +0000<br>Subject:[EXTERNAL] Undeliverable: Ticket Assigned - NMS - alarm host-1</p>'
    . '<p>Delivery has failed to these recipients or groups:<br>box@example.test<br>The recipient\'s mailbox is full and can\'t accept messages now.</p>'
    . '<p>Diagnostic information for administrators:<br>Generating server: srv.example.test<br>Remote server returned \'554 5.2.2 mailbox full ' . $junk . '\'<br>Original message headers:<br>ARC-Seal: i=2; a=rsa-sha256; ' . $junk . '</p>';

check('a bounce notice is recognised', B::isBounce($bounce));
$c = B::clean($bounce);
check('clean() returns a shorter body', $c !== null && strlen($c) < strlen($bounce) / 10, (string) strlen((string) $c));
check('kept: requester line, From/To/Date/Subject, the failure statement and its reason', $c !== null && str_contains($c, 'Requester (source): Postmaster') && str_contains($c, 'Subject:[EXTERNAL] Undeliverable') && str_contains($c, 'Delivery has failed to these recipients') && str_contains($c, "mailbox is full"));
check('dropped: diagnostic data, headers block, caution banner', $c !== null && !str_contains($c, 'Generating server') && !str_contains($c, 'ARC-Seal') && !str_contains($c, '1.84300') && !str_contains($c, 'CAUTION:'));
check('a short italic note says what was removed', $c !== null && str_contains($c, B::REMOVED));
check('the result is HTML-escaped text (no raw angle brackets from the addresses)', $c !== null && str_contains($c, '&lt;postmaster@example.test&gt;') && !str_contains($c, '<postmaster@'));
check('cleaning twice does nothing (an already cleaned body is not a bounce)', $c !== null && !B::isBounce($c) && B::clean($c) === null);

// cut at "Original message headers" when there is no "Diagnostic ..." line, in any letter case
$alt = '<p>Final-Recipient: rfc822;box@example.test</p><p>Delivery has failed to these recipients</p><div>Original Message Headers</div><div>ARC-Seal: x ' . $junk . '</div>';
$c2 = B::clean($alt);
check('no "Diagnostic" line: cut at the headers block, case-insensitive', $c2 !== null && str_contains($c2, 'Delivery has failed') && !str_contains($c2, 'ARC-Seal'));

// things that must NOT be touched
check('an ordinary ticket is left alone', B::clean('<p>The printer is down. Original message headers are not relevant here.</p>') === null);
check('a mail that only mentions headers, without a failure notice, is left alone', B::clean('<p>Please send me the original message headers of that mail.</p>') === null);
check('empty body', B::clean('') === null && !B::isBounce(''));
check('a failure notice without a cut line is left alone (nothing to cut)', B::clean('<p>Undeliverable: the mailbox is full.</p>') === null);

done();
