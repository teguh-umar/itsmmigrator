<?php

// The heartbeat per item (at most every 10 s) and the sleep-gap note. Offline.
require __DIR__ . '/bootstrap.php';
require_once ITSM_ROOT . '/src/Counters.php';
require_once ITSM_ROOT . '/src/CategoryFilter.php';
require_once ITSM_ROOT . '/src/DateRange.php';
require_once ITSM_ROOT . '/src/Context.php';

use GlpiPlugin\Itsmmigrator\Context;
use GlpiPlugin\Itsmmigrator\Counters;

$log = []; $beats = 0;
$c = new Context(['id' => 1], [], [], new Counters(), function (string $m) use (&$log) { $log[] = $m; });
$c->onTick(function () use (&$beats) { $beats++; });
$t = 1_000_000;
$c->tick($t);                       // first item: beat
$c->tick($t + 3); $c->tick($t + 6); // within 10 s: no beat
check('first item beats, items within 10 s do not', $beats === 1);
$c->tick($t + 12);
check('a beat at most every 10 seconds', $beats === 2);
for ($i = 1; $i <= 100; $i++) { $c->tick($t + 12 + $i * 8); } // 100 tickets at 8 s each: a page that used to beat once
check('100 slow items (8 s each) beat about every other item, not once per page', $beats >= 50 && $beats <= 52, "beats=$beats");
check('no gap note while the run is steady', array_filter($log, fn ($m) => str_contains($m, 'asleep')) === []);
$before = $beats;
$c->tick($t + 12 + 800 + 16 * 3600); // 16 hours later
check('a clock jump is logged in minutes, once, and the heartbeat is refreshed at once', count(array_filter($log, fn ($m) => str_contains($m, 'asleep'))) === 1 && $beats === $before + 1 && str_contains(implode('', $log), '960 minutes'), implode('|', $log));
$c2 = new Context(['id' => 1], [], [], new Counters());
$c2->tick(5); check('no beat callback set: tick is harmless', true);
$en = (string) file_get_contents(ITSM_ROOT . '/src/Engine.php');
check('engine ticks after every item and wires the heartbeat', str_contains($en, '$ctx->tick();') && str_contains($en, '$ctx->onTick(static fn () => Lock::heartbeat($runId));'));
done();
