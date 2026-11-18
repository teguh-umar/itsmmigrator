<?php

require __DIR__ . '/bootstrap.php';
foreach (['Counters', 'Writer/UserWriter', 'Engine'] as $f) {
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
check('engine acquires the lock before running modules', strpos($src, 'Lock::acquire') < strpos($src, 'runModule($module'));
check('engine releases the lock in a finally block', preg_match('/finally\s*\{[^}]*Lock::release/s', $src) === 1);
check('engine restores the writing flag', str_contains($src, 'self::$writing = $prev'));

done();
