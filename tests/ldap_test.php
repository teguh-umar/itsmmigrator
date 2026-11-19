<?php

require __DIR__ . '/bootstrap.php';

// Stand-ins for core: a directory that can be configured to answer, fail or raise warnings, and a user table.
class AuthLDAP
{
    public const IDENTIFIER_LOGIN = 'login';
    public const IDENTIFIER_EMAIL = 'email';
    public const ACTION_IMPORT    = 0;
    public const USER_IMPORTED    = 1;

    public static array $cfg = ['connect' => true, 'raise' => false, 'error' => false, 'found' => true];
    public static int $imports = 0;
    public static int $searches = 0;
    public static int $connects = 0;
    public static int $failNext = 0; // the next N searches fail with a core error (a tired connection), then recover
    public array $fields = [];

    public function getFromDB($id) { $this->fields = ['basedn' => 'OU=Fake,DC=example,DC=test', 'condition' => '', 'email1_field' => 'mail']; return true; }
    public function connect() { self::$connects++; return self::$cfg['connect'] ? new stdClass() : false; }
    public function getLdapIdentifierToUse() { return 'samaccountname'; }

    public static function searchUserDn($ds, $options = [], ?bool &$error = null)
    {
        self::$searches++;
        if (self::$cfg['raise']) {
            // Exactly what core does on a failed search: a warning carrying the base DN and the filter.
            trigger_error('LDAP search with base DN `OU=Secret,DC=corp,DC=example` and filter `(mail=someone@example.test)` failed', E_USER_WARNING);
        }
        if (self::$cfg['error'] || self::$failNext > 0) {
            if (self::$failNext > 0) { self::$failNext--; }
            $error = true;
            return false;
        }
        return self::$cfg['found'] ? ['dn' => 'CN=Someone,OU=Fake,DC=example,DC=test', 'samaccountname' => 'someone'] : false;
    }

    public static function getFieldValue($infos, $field) { return $infos[$field] ?? null; }

    public static function ldapImportUserByServerId(array $params, $action, $server, $display = false, bool $batch = false, bool &$refused = false)
    {
        self::$imports++;
        return ['action' => self::USER_IMPORTED, 'id' => 99];
    }
}
class User
{
    public static array $known = []; // sync_field => id
    public array $fields = [];
    public function getFromDBbySyncField($v) { if (isset(self::$known[$v])) { $this->fields = ['id' => self::$known[$v], 'is_deleted' => 0]; return true; } return false; }
    public function getFromDBbyDn($dn) { return false; }
}
$DB = new class {
    public array $servers = [['id' => 1], ['id' => 2]];
    public function request(array $c) { return $this->servers; }
};
require_once ITSM_ROOT . '/src/Matching/LdapLookupInterface.php';
require_once ITSM_ROOT . '/src/Matching/GlpiLdapLookup.php';

use GlpiPlugin\Itsmmigrator\Matching\GlpiLdapLookup as L;

$reset = function (array $cfg = []) {
    AuthLDAP::$cfg = array_merge(['connect' => true, 'raise' => false, 'error' => false, 'found' => true], $cfg);
    AuthLDAP::$imports = AuthLDAP::$searches = AuthLDAP::$connects = AuthLDAP::$failNext = 0;
    User::$known = [];
};
$log = [];
$mk = function () use (&$log) { return new L(function (string $m) use (&$log) { $log[] = $m; }); };

// ---- a dry run never imports
$reset(); $l = $mk();
$r = $l->findAndImport(['someone@example.test'], '', true);
check('dry run: the person is found, "would import", users_id 0', $r === ['users_id' => 0, 'imported' => true]);
check('dry run: the import call is NEVER made', AuthLDAP::$imports === 0);

// ---- a real run imports exactly once
$reset(); $l = $mk();
$r = $l->findAndImport(['someone@example.test'], '', false);
check('real run: imported as GLPI user 99', $r === ['users_id' => 99, 'imported' => true] && AuthLDAP::$imports === 1);

// ---- an existing GLPI user is handed back and never sent to the import (core would re-activate an inactive one)
$reset(['found' => true]); User::$known['someone'] = 42; $l = $mk();
foreach ([false, true] as $dry) {
    $r = $l->findAndImport(['someone@example.test'], '', $dry);
    check('existing user (dry=' . ($dry ? 'yes' : 'no') . '): returned untouched, imported=false', $r === ['users_id' => 42, 'imported' => false]);
}
check('existing user: the import call is never made', AuthLDAP::$imports === 0);

// ---- not in the directory
$reset(['found' => false]); $l = $mk();
check('not in the directory -> null', $l->findAndImport(['nobody@example.test'], '') === null && AuthLDAP::$imports === 0);

// ---- warnings from a failing search never reach the log or the page, and never repeat per user
$reset(['raise' => true, 'error' => true]);
$warnings = 0;
set_error_handler(function () use (&$warnings) { $warnings++; return true; });
$log = []; $l = $mk();
$r1 = $l->findAndImport(['a@example.test'], 'a');
$searchesAfterFirst = AuthLDAP::$searches;
$r2 = $l->findAndImport(['b@example.test'], 'b');
restore_error_handler();
check('failing search: no PHP warning escapes to the caller (nothing for the log or the page)', $warnings === 0, "warnings=$warnings");
check('failing search: answered null, no exception', $r1 === null && $r2 === null);
check('failing search: each directory gets one retry on a fresh connection, then is switched off for the run', $searchesAfterFirst === 4 && AuthLDAP::$searches === 4, 'searches=' . AuthLDAP::$searches);
check('failing search: the log never carries a DN, a filter or an address', array_filter($log, fn ($m) => stripos($m, 'OU=') !== false || stripos($m, 'example') !== false || str_contains($m, '@') || stripos($m, 'mail=') !== false) === [], json_encode($log));
check('failing search: the log says which directory was skipped, by number', count(array_filter($log, fn ($m) => str_contains($m, 'directory 1') || str_contains($m, 'directory 2'))) === 2, json_encode($log));

// ---- a tired connection: one failure is answered with a fresh connection and the retry succeeds (directory stays in use)
$reset(['found' => true]); AuthLDAP::$failNext = 1; $log = []; $l = $mk();
$r = $l->findAndImport(['a@example.test'], '', true);
check('transient search error: reconnect and retry find the person', $r === ['users_id' => 0, 'imported' => true] && AuthLDAP::$connects === 2 && AuthLDAP::$searches === 2, 'connects=' . AuthLDAP::$connects . ' searches=' . AuthLDAP::$searches);
check('transient search error: the directory is not switched off', array_filter($log, fn ($m) => str_contains($m, 'skipped')) === []);

// ---- long loops: a fresh connection every RECONNECT_EVERY searches, not one per person
$reset(['found' => false]); $l = $mk();
for ($i = 0; $i < 600; $i++) { $l->findAndImport(['u' . $i . '@example.test'], '', true); }
check('600 searches on 2 directories: reconnect every ' . L::RECONNECT_EVERY . ' (3 connections per directory, not 600)', AuthLDAP::$connects === 6 && AuthLDAP::$searches === 1200, 'connects=' . AuthLDAP::$connects);

// ---- unreachable directory
$reset(['connect' => false]); $log = []; $l = $mk();
$l->findAndImport(['a@example.test'], ''); $l->findAndImport(['b@example.test'], '');
check('unreachable: connected once per directory, then skipped', AuthLDAP::$connects === 2 && AuthLDAP::$searches === 0);

// ---- quiet() keeps the caller's own error handler intact
$seen = 0;
set_error_handler(function () use (&$seen) { $seen++; return true; });
L::quiet(function () { trigger_error('inside', E_USER_WARNING); return 1; }, $f1);
trigger_error('outside', E_USER_WARNING);
restore_error_handler();
check('quiet(): swallows inside, restores the previous handler (the outside warning still reaches it)', $seen === 1 && $f1 === true);
check('quiet(): an exception is swallowed and reported as failed', L::quiet(function () { throw new RuntimeException('x'); }, $f2) === false && $f2 === true);

// ---- the writer hands the dry-run flag to the lookup
$w = (string) file_get_contents(ITSM_ROOT . '/src/Writer/UserWriter.php');
check('UserWriter passes the dry-run flag to the directory step', str_contains($w, 'findAndImport($emails, $login, $ctx->dryRun())'));
$i = (string) file_get_contents(ITSM_ROOT . '/src/Matching/LdapLookupInterface.php');
check('the interface carries the dry-run flag', str_contains($i, 'bool $dryRun = false'));

done();
