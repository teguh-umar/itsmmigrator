<?php

// Offline test harness: no GLPI, no DB.
// Run one suite:  php tests/<name>_test.php
// Run all:        php tests/run_all.php
// Count results with an ANCHORED grep ('^PASS'), never a bare 'PASS' (hub §4).
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Jakarta');

define('ITSM_ROOT', dirname(__DIR__));

// Suite shared files: absent dir must make byte-identity checks FAIL, never skip.
$__shared = realpath(ITSM_ROOT . '/../../shared/pgnsuite');
define('SHARED', $__shared !== false ? $__shared : ITSM_ROOT . '/../../shared/pgnsuite');

/** LF-normalised sha256 of a file, or null when unreadable (core.autocrlf=true here). */
function lf_hash(string $path): ?string
{
    $c = is_file($path) ? file_get_contents($path) : false;
    return $c === false ? null : hash('sha256', str_replace("\r\n", "\n", $c));
}

// Real GLPI bit values (hub §7: invented values let a wrong mask pass).
foreach (['READ' => 1, 'UPDATE' => 2, 'CREATE' => 4, 'DELETE' => 8, 'PURGE' => 16] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

$GLOBALS['__itsm_pass'] = 0;
$GLOBALS['__itsm_fail'] = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['__itsm_pass']++;
        echo "PASS {$label}\n";
        return;
    }
    $GLOBALS['__itsm_fail']++;
    echo "FAIL {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

function done(): void
{
    $pass = $GLOBALS['__itsm_pass'];
    $fail = $GLOBALS['__itsm_fail'];
    echo ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ({$pass} passed, {$fail} failed)\n";
    exit($fail === 0 ? 0 : 1);
}

/** Load src files by path relative to src/, in order (no autoloader offline). */
function load_src(string ...$files): void
{
    foreach ($files as $f) {
        require_once ITSM_ROOT . '/src/' . $f . '.php';
    }
}

if (!function_exists('__')) {
    function __($s, $d = null) { return $s; }
}
if (!function_exists('__s')) {
    function __s($s, $d = null) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('_n')) {
    function _n($s, $p, $n, $d = null) { return $n == 1 ? $s : $p; }
}
if (!function_exists('_sx')) {
    function _sx($ctx, $s, $d = null) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('htmlescape')) {
    function htmlescape($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}

/**
 * Faithful to core Session::haveAccessToEntity() (verified in GLPI 11.0.5,
 * src/Session.php:1394): a negative id is refused, an active entity is granted,
 * and when (and only when) the item is recursive an ANCESTOR of an active entity
 * is granted too. glpiactiveentities never holds ancestors, so a stub that
 * ignores $rec tests semantics production does not have.
 *
 * @param list<int>            $active    glpiactiveentities
 * @param array<int,list<int>> $ancestors entity id => its ancestor ids
 */
function entity_access(int $id, bool $rec, array $active, array $ancestors): bool
{
    if ($id < 0) {
        return false;
    }
    if (in_array($id, $active, true)) {
        return true;
    }
    if (!$rec) {
        return false;
    }
    foreach ($active as $a) {
        if (in_array($id, $ancestors[(int) $a] ?? [], true)) {
            return true;
        }
    }
    return false;
}
