<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/glpi_stubs.php';
foreach (['DateRange', 'Engine'] as $f) {
    require_once ITSM_ROOT . "/src/$f.php";
}

use GlpiPlugin\Itsmmigrator\DateRange as R;
use GlpiPlugin\Itsmmigrator\Engine;

$d = fn (string $s) => new DateTimeImmutable($s, new DateTimeZone('UTC'));
$u = fn (?DateTimeImmutable $x) => $x?->format('Y-m-d H:i:s');

// ---- parsing: a bare date as an END is the whole day
check('from: a date is the start of that day (UTC)', $u(R::parse('2026-01-01', false)) === '2026-01-01 00:00:00');
check('until: a bare date is the END of that day', $u(R::parse('2026-01-31', true)) === '2026-01-31 23:59:59');
check('until: an ISO time is kept as given', $u(R::parse('2026-01-31T10:00:00Z', true)) === '2026-01-31 10:00:00');
check('nothing / rubbish -> null', R::parse(null, true) === null && R::parse('', false) === null && R::parse('last week', true) === null);

// ---- settings: flags win, an EMPTY flag means "not given", options fill in
$opt = ['import_since' => '2025-01-01', 'import_until' => '2025-12-31', 'import_date_basis' => 'created'];
$r = R::fromSettings($opt, []);
check('options only', $u($r->from) === '2025-01-01 00:00:00' && $u($r->until) === '2025-12-31 23:59:59' && $r->basis === 'created');
$r = R::fromSettings($opt, ['since' => '2024-06-01', 'until' => '2024-06-30', 'date_basis' => 'updated']);
check('flags win over the options', $u($r->from) === '2024-06-01 00:00:00' && $u($r->until) === '2024-06-30 23:59:59' && $r->basis === 'updated');
$r = R::fromSettings($opt, ['since' => '', 'until' => ' ', 'date_basis' => '']);
check('empty form fields mean "not given": the source options apply', $u($r->from) === '2025-01-01 00:00:00' && $u($r->until) === '2025-12-31 23:59:59' && $r->basis === 'created');
$r = R::fromSettings([], []);
check('nothing set: no range, the update reading', $r->from === null && $r->until === null && $r->basis === 'updated' && !$r->limitsTheRun());
check('an unknown basis falls back to updated', R::fromSettings(['import_date_basis' => 'whenever'], [])->basis === 'updated');

// ---- the update reading: the end date stops the read, the start is the source's own filter
$r = new R($d('2026-01-01'), $d('2026-01-31 23:59:59'), 'updated');
check('updated: a ticket past the end stops the reading', $r->pastEnd($d('2026-02-01 00:00:00')) && !$r->pastEnd($d('2026-01-31 23:59:59')) && !$r->pastEnd(null));
check('updated: allows everything up to and including the end, never filters the start itself', $r->allows($d('2020-01-01'), $d('2026-01-31 23:59:59')) && !$r->allows($d('2025-01-01'), $d('2026-02-01')) && $r->allows($d('2020-01-01'), $d('2025-12-31')));
check('updated: an end date limits the run (the watermark must not move)', $r->limitsTheRun());
check('updated without an end date does not limit the run', !(new R($d('2026-01-01'), null, 'updated'))->limitsTheRun());

// ---- the creation reading: both ends filter, no early stop
$r = new R($d('2026-01-01'), $d('2026-01-31 23:59:59'), 'created');
check('created: inside the range', $r->allows($d('2026-01-15'), $d('2026-06-01')));
check('created: before the start, or after the end, is left out', !$r->allows($d('2025-12-31 23:59:59'), $d('2026-06-01')) && !$r->allows($d('2026-02-01'), $d('2026-06-01')));
check('created: the end date never stops the reading (a late update can belong to an early ticket)', !$r->pastEnd($d('2030-01-01')));
check('created: always limits the run', $r->limitsTheRun() && (new R(null, null, 'created'))->limitsTheRun());
check('a ticket without a date is not dropped by a date', $r->allows(null, null));

// ---- reading from: the creation reading ignores the watermark
$S = fn (array $o, ?string $wm = null) => ['options' => json_encode($o), 'watermark' => $wm];
check('created reading: starts at import_since even with a watermark', $u(Engine::effectiveSince($S(['import_since' => '2025-01-01', 'import_date_basis' => 'created'], '2026-11-18 10:00:00'), [])) === '2025-01-01 00:00:00');
check('updated reading with a watermark is still a delta', $u(Engine::effectiveSince($S(['import_since' => '2025-01-01'], '2026-11-18 10:00:00'), [])) === '2026-11-18 01:00:00');
check('an empty --since flag does not hide import_since', $u(Engine::effectiveSince($S(['import_since' => '2025-01-01']), ['since' => ''])) === '2025-01-01 00:00:00');

// ---- wiring
$en = (string) file_get_contents(ITSM_ROOT . '/src/Engine.php');
check('engine: the end date finishes the tickets module, and that is not "complete" for the watermark', str_contains($en, '$rangeEnd') && str_contains($en, '!$limitHit && !$rangeEnd'));
check('engine: a date-limited run keeps the watermark still', str_contains($en, 'limitsTheRun()'));
$rc = (string) file_get_contents(ITSM_ROOT . '/src/Console/RunCommand.php');
check('CLI has --until and --date-basis, validated', str_contains($rc, "addOption('until'") && str_contains($rc, "addOption('date-basis'") && str_contains($rc, '--date-basis must be updated or created'));
$wr = (string) file_get_contents(ITSM_ROOT . '/src/WebRun.php');
check('web run whitelists until and date_basis', str_contains($wr, "'until' => 'string'") && str_contains($wr, "'date_basis' => 'basis'"));

done();
