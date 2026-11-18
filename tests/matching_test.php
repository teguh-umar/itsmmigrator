<?php

require __DIR__ . '/bootstrap.php';
require_once ITSM_ROOT . '/src/Matching/UserMatcher.php';
require_once ITSM_ROOT . '/src/Matching/VisibleMatcher.php';
require_once ITSM_ROOT . '/src/MapRepository.php';
require_once ITSM_ROOT . '/src/Lock.php';

use GlpiPlugin\Itsmmigrator\Lock;
use GlpiPlugin\Itsmmigrator\MapRepository;
use GlpiPlugin\Itsmmigrator\Matching\UserMatcher as U;
use GlpiPlugin\Itsmmigrator\Matching\VisibleMatcher as V;

// ---- e-mails and logins
check('emails are lower-cased, trimmed, deduplicated', U::normalizeEmails([' A@X.com ', 'a@x.com', '', 'nomail']) === ['a@x.com']);
check('login drops DOMAIN\\ prefix', U::normalizeLogin('CORP\\Budi.S') === 'budi.s');
check('login drops @domain suffix', U::normalizeLogin('budi.s@corp.example') === 'budi.s');
check('empty login is empty', U::normalizeLogin(null) === '');

$pairs = [['old.example.co.id', 'new.example.com']];
check('alias: old -> new', U::aliasEmails(['ani@old.example.co.id'], $pairs) === ['ani@new.example.com']);
check('alias: new -> old (both directions)', U::aliasEmails(['ani@new.example.com'], $pairs) === ['ani@old.example.co.id']);
check('alias: unrelated domain gives nothing', U::aliasEmails(['ani@other.org'], $pairs) === []);
check('alias: a direct address is not repeated', U::aliasEmails(['a@old.example.co.id', 'a@new.example.com'], $pairs) === []);

// ---- decisions
$d = U::decide([7], []);
check('one email hit -> matched', $d['outcome'] === U::MATCHED && $d['users_id'] === 7);
$d = U::decide([7], [7]);
check('email and login agree -> matched', $d['outcome'] === U::MATCHED && $d['users_id'] === 7);
$d = U::decide([7], [9]);
check('email and login on different users -> needs_review with both', $d['outcome'] === U::NEEDS_REVIEW && $d['candidates'] === [7, 9] && $d['users_id'] === null);
$d = U::decide([7, 8], []);
check('email shared by two users -> needs_review', $d['outcome'] === U::NEEDS_REVIEW && $d['candidates'] === [7, 8]);
$d = U::decide([], [5]);
check('login only -> matched', $d['outcome'] === U::MATCHED && $d['users_id'] === 5);
$d = U::decide([], [5, 6]);
check('two login hits -> needs_review', $d['outcome'] === U::NEEDS_REVIEW);
$d = U::decide([], []);
check('nothing -> not_found', $d['outcome'] === U::NOT_FOUND && $d['users_id'] === null);
$d = U::decide([7, 7], []);
check('the same user twice is not ambiguity', $d['outcome'] === U::MATCHED);

// ---- visibility (groups, categories)
// Tree: 0 root > 1 parent > 2 target; 3 is a sibling branch.
$anc = [1, 0];
$rows = [
    ['id' => 10, 'entities_id' => 1, 'is_recursive' => 1], // parent, recursive: visible
    ['id' => 11, 'entities_id' => 1, 'is_recursive' => 0], // parent, not recursive: invisible
    ['id' => 12, 'entities_id' => 3, 'is_recursive' => 1], // sibling branch: invisible
    ['id' => 13, 'entities_id' => 0, 'is_recursive' => 1], // root, recursive: visible
    ['id' => 14, 'entities_id' => 2, 'is_recursive' => 0], // the target itself
];
$vis = array_column(V::visible($rows, 2, $anc), 'id');
check('visible: target first, then nearest ancestor, then root', $vis === [14, 10, 13], json_encode($vis));
check('visible: non-recursive ancestor row is hidden', !in_array(11, $vis, true));
check('visible: another branch is hidden', !in_array(12, $vis, true));
check('visible: nothing matches -> empty', V::visible([['id' => 1, 'entities_id' => 3, 'is_recursive' => 1]], 2, $anc) === []);
check('key is case/space insensitive', V::key('  ITOC   Team ') === V::key('itoc team'));

// ---- map decisions (spec §5 rule 1)
check('no row -> create', MapRepository::decide(null) === 'create');
check('ok -> skip', MapRepository::decide(['status' => 'ok']) === 'skip');
check('in_progress -> resume, never a new parent', MapRepository::decide(['status' => 'in_progress']) === 'resume');
check('failed -> skip by default', MapRepository::decide(['status' => 'failed']) === 'skip');
check('failed -> retry with --retry-failed', MapRepository::decide(['status' => 'failed'], ['retry_failed' => true]) === 'retry');
check('orphan -> skip by default', MapRepository::decide(['status' => 'orphan']) === 'skip');
check('orphan -> create with --recreate-missing', MapRepository::decide(['status' => 'orphan'], ['recreate_missing' => true]) === 'create');
check('needs_review -> skip until an admin resolves it', MapRepository::decide(['status' => 'needs_review']) === 'skip');
check('skipped / source_deleted -> skip', MapRepository::decide(['status' => 'skipped']) === 'skip' && MapRepository::decide(['status' => 'source_deleted']) === 'skip');

// ---- lock decisions (spec §5 rule 4)
$now = 1_000_000;
$live  = [['id' => 1, 'sources_id' => 3, 'runner' => 'cli', 'started_at' => 'T0', 'heartbeat_at' => date('Y-m-d H:i:s', $now - 60)]];
$stale = [['id' => 2, 'sources_id' => 3, 'runner' => 'web', 'started_at' => 'T0', 'heartbeat_at' => date('Y-m-d H:i:s', $now - 1000)]];
$v = Lock::evaluate([], $now, false);
check('lock: free -> proceed', $v['proceed'] === true && $v['abort_ids'] === []);
$v = Lock::evaluate($live, $now, false);
check('lock: live run refuses, names source/runner/started_at', !$v['proceed'] && str_contains($v['message'], 'Run already active') && str_contains($v['message'], 'cli') && str_contains($v['message'], 'T0'));
$v = Lock::evaluate($live, $now, true);
check('lock: --force-unlock never aborts a LIVE run', !$v['proceed'] && $v['abort_ids'] === []);
$v = Lock::evaluate($stale, $now, false);
check('lock: stale run still refuses, and says to force', !$v['proceed'] && $v['stale'] && str_contains($v['message'], 'force'));
$v = Lock::evaluate($stale, $now, true);
check('lock: stale run + force -> proceed and abort that run', $v['proceed'] && $v['abort_ids'] === [2]);
$v = Lock::evaluate(array_merge($stale, $live), $now, true);
check('lock: one live among stale rows is still live', !$v['proceed']);

done();
