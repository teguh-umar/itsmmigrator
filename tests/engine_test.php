<?php

require __DIR__ . '/bootstrap.php';
foreach (['Counters', 'DateRange', 'Writer/UserWriter', 'Engine'] as $f) {
    require_once ITSM_ROOT . "/src/$f.php";
}

use GlpiPlugin\Itsmmigrator\Counters;
use GlpiPlugin\Itsmmigrator\Engine;
use GlpiPlugin\Itsmmigrator\Writer\UserWriter;

// ---- module plan
$p = Engine::planModules([]);
check('no request = every module, canonical order', $p['run'] === Engine::MODULES && $p['unknown'] === []);
check('users runs before groups (groups need the user map)', array_search('users', Engine::MODULES) < array_search('groups', Engine::MODULES));
$p = Engine::planModules(['categories', 'users']);
check('requested modules are put in canonical order', $p['run'] === ['users', 'categories']);
$p = Engine::planModules(['users', 'nonsense', 'users']);
check('unknown module reported, duplicates dropped', $p['run'] === ['users'] && $p['unknown'] === ['nonsense']);

// ---- counters
$c = new Counters();
$c->add('users', 'created'); $c->add('users', 'created', 2); $c->add('groups', 'failed');
check('counters add up', $c->get('users', 'created') === 3 && $c->get('users', 'matched') === 0);
check('all() lists every key, zeros included', count($c->all()['users']) === count(Counters::KEYS));
check('a failure is detected', $c->hasFailures() && !(new Counters())->hasFailures());
try { $c->add('users', 'bogus'); check('unknown counter refused', false); } catch (InvalidArgumentException $e) { check('unknown counter refused', true); }

// ---- profile choice (spec §4a assign_profiles)
check('none: nobody gets a profile', UserWriter::profileFor('none', false, 5, 6) === 0 && UserWriter::profileFor('none', true, 5, 6) === 0);
check('requester_only: requester gets self-service', UserWriter::profileFor('requester_only', false, 5, 6) === 5);
check('requester_only: technician gets nothing', UserWriter::profileFor('requester_only', true, 5, 6) === 0);
check('all: technician gets the tech profile', UserWriter::profileFor('all', true, 5, 6) === 6);
check('all: requester gets self-service', UserWriter::profileFor('all', false, 5, 6) === 5);
check('unknown mode behaves as none', UserWriter::profileFor('weird', false, 5, 6) === 0);

// ---- the engine takes the lock before it touches anything, and releases in finally
$src = file_get_contents(ITSM_ROOT . '/src/Engine.php');
check('engine acquires the lock before running modules', strpos($src, 'Lock::acquire') < strpos($src, '$this->step($pos'));
check('engine releases the lock in a finally block', preg_match('/finally\s*\{[^}]*Lock::release/s', $src) === 1);
check('engine restores the writing flag', str_contains($src, 'self::$writing = $prev'));

// ---- position (shared by the CLI and the web runner)
$pos = Engine::newPosition(['users', 'tickets']);
check('a new position starts on the first module, page 1', $pos['mi'] === 0 && $pos['page'] === 1 && $pos['cursor'] === null && $pos['offset'] === 0 && $pos['seen'] === 0 && $pos['modules'] === ['users', 'tickets']);
check('the web batch for tickets is small, for the rest large', Engine::WEB_BATCH_TICKETS <= 20 && Engine::WEB_BATCH_OTHER >= 50);
check('Counters survive a round trip through a stored run', (function () {
    $c = new Counters(); $c->add('users', 'created', 3); $c->add('tickets', 'failed', 1);
    return Counters::fromArray($c->all())->all() === $c->all();
})());
// ---- watermark (spec §8.4)
$utc = static fn (?DateTimeImmutable $d): ?string => $d?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
check('no watermark, no flags -> read everything', Engine::effectiveSince(['watermark' => null], []) === null && Engine::effectiveSince([], []) === null);
check('watermark minus 2 hours (stored in local time, read as UTC)', $utc(Engine::effectiveSince(['watermark' => '2024-03-10 10:00:00'], [])) === '2024-03-10 01:00:00', (string) $utc(Engine::effectiveSince(['watermark' => '2024-03-10 10:00:00'], [])));
check('--full ignores the watermark', Engine::effectiveSince(['watermark' => '2024-03-10 10:00:00'], ['full' => true]) === null);
check('--retry-failed and --recreate-missing read everything too', Engine::effectiveSince(['watermark' => '2024-03-10 10:00:00'], ['retry_failed' => true]) === null && Engine::effectiveSince(['watermark' => '2024-03-10 10:00:00'], ['recreate_missing' => true]) === null);
check('an explicit --since beats the watermark and --full', $utc(Engine::effectiveSince(['watermark' => '2024-03-10 10:00:00'], ['since' => '2024-01-01T00:00:00Z', 'full' => true])) === '2024-01-01 00:00:00');

$pos = ['tickets_complete' => true, 'max_updated' => '2024-03-12T03:00:00Z'];
check('advances to the max seen, in local time', Engine::watermarkToWrite(null, $pos, 'success', []) === '2024-03-12 10:00:00');
check('advances on partial (item failures) too', Engine::watermarkToWrite('2024-03-01 00:00:00', $pos, 'partial', []) === '2024-03-12 10:00:00');
check('never on failed (a fetch error)', Engine::watermarkToWrite(null, $pos, 'failed', []) === null);
check('never on aborted', Engine::watermarkToWrite(null, $pos, 'aborted', []) === null);
check('never in a dry run', Engine::watermarkToWrite(null, $pos, 'success', ['dry_run' => true]) === null);
check('never when --limit cut the list', Engine::watermarkToWrite(null, $pos, 'success', ['limit' => 50]) === null);
check('never when the tickets module did not finish', Engine::watermarkToWrite(null, ['max_updated' => '2024-03-12T03:00:00Z'], 'success', []) === null);
check('never when no ticket was seen', Engine::watermarkToWrite(null, ['tickets_complete' => true], 'success', []) === null);
check('never backwards', Engine::watermarkToWrite('2024-03-20 00:00:00', $pos, 'success', []) === null && Engine::watermarkToWrite('2024-03-12 10:00:00', $pos, 'success', []) === null);

$cd = new Counters(); $cd->detail('users', 'matched_by_email', 2); $cd->detail('users', 'skipped_no_match');
check('counter details: kept apart from the main counters, never user data', $cd->details() === ['users' => ['matched_by_email' => 2, 'skipped_no_match' => 1]] && $cd->all() === []);
check('the engine result carries the details, the CLI prints them', str_contains((string) file_get_contents(ITSM_ROOT . '/src/Engine.php'), "'details' => \$counters->details()") && str_contains((string) file_get_contents(ITSM_ROOT . '/src/Console/RunCommand.php'), 'detail: '));
// ---- import_since (a floor for the phase) and phases
$S = fn (array $o, ?string $wm = null) => ['options' => json_encode($o), 'watermark' => $wm];
check('import_since is read as a UTC date', $utc(Engine::importSince($S(['import_since' => '2026-01-01']))) === '2026-01-01 00:00:00' && Engine::importSince($S([])) === null && Engine::importSince($S(['import_since' => 'rubbish'])) === null);
check('first phase: no watermark -> read from import_since', $utc(Engine::effectiveSince($S(['import_since' => '2026-01-01']), [])) === '2026-01-01 00:00:00');
check('after a phase the watermark wins (a delta), but never below import_since', $utc(Engine::effectiveSince($S(['import_since' => '2026-01-01'], '2026-11-18 10:00:00'), [])) === '2026-11-18 01:00:00' && $utc(Engine::effectiveSince($S(['import_since' => '2026-12-01'], '2026-11-18 10:00:00'), [])) === '2026-12-01 00:00:00');
check('a LATER, wider phase: lower import_since + "read everything" reads from the new floor', $utc(Engine::effectiveSince($S(['import_since' => '2025-01-01'], '2026-11-18 10:00:00'), ['full' => true])) === '2025-01-01 00:00:00');
check('--retry-failed reads from import_since too (not from 2010)', $utc(Engine::effectiveSince($S(['import_since' => '2025-01-01'], '2026-11-18 10:00:00'), ['retry_failed' => true])) === '2025-01-01 00:00:00');
check('an explicit --since still beats everything', $utc(Engine::effectiveSince($S(['import_since' => '2026-01-01'], '2026-11-18 10:00:00'), ['since' => '2024-05-05T00:00:00Z', 'full' => true])) === '2024-05-05 00:00:00');
check('nothing set: everything', Engine::effectiveSince($S([]), []) === null && Engine::effectiveSince($S([]), ['full' => true]) === null);
done();
