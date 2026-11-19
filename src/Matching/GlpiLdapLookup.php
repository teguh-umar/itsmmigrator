<?php

namespace GlpiPlugin\Itsmmigrator\Matching;

/**
 * Directory lookup through GLPI's own AuthLDAP (verified in 11.0.13: `AuthLDAP::searchUserDn()` and
 * `AuthLDAP::ldapImportUserByServerId()`).
 *
 * Safety rules:
 *  1. The search is read-only. A person who is already a GLPI user (by sync field or DN) is returned
 *     as is and NEVER passed to the import call, because core's import of an existing inactive user
 *     "restores" (re-activates) it, and a matched user must never be modified (spec §4a).
 *  2. A DRY RUN never imports: it only searches and reports "would import".
 *  3. Only active directories are queried, default one first. A directory that cannot be reached, cannot be
 *     bound, or whose search fails is switched off for the rest of the run: never fatal, never retried per user.
 *  4. Core raises a PHP warning for every failed search and puts the base DN and the filter (an e-mail
 *     address) in it. Those go to the PHP log and, for an administrator in debug mode, into the page. They
 *     are swallowed here: nothing about the directory or the person is logged or shown, only "directory N
 *     could not be searched".
 */
final class GlpiLdapLookup implements LdapLookupInterface
{
    /** @var callable(string):void */
    private $logger;

    /** Searches on one connection before a fresh one is opened: long loops make the directory refuse a tired connection. */
    public const RECONNECT_EVERY = 250;

    /** @var array<int,true> directories that failed: not retried for every user of the run */
    private array $down = [];

    /** @var array<int,array{ds:mixed,cfg:\AuthLDAP,n:int}> the open connection of each directory and its search count */
    private array $conn = [];

    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger ?? static function (string $m): void {
        };
    }

    public function findAndImport(array $emails, string $login, bool $dryRun = false): ?array
    {
        foreach ($this->servers() as $serverId) {
            if (isset($this->down[$serverId])) {
                continue;
            }
            if ($this->connection($serverId) === null) {
                $this->markDown($serverId, 'not reachable or bind refused');
                continue;
            }

            foreach (self::attempts($emails, $login) as [$method, $value]) {
                $error = false;
                $hit   = $this->searchOn($serverId, $method, $value, $error);
                if ($error) {
                    $this->markDown($serverId, 'search failed after a fresh connection');
                    break;
                }
                if ($hit === null) {
                    continue;
                }
                // Rule 1: already a GLPI user -> hand it back untouched.
                $existing = $this->existingUser($hit['login'], $hit['dn']);
                if ($existing > 0) {
                    return ['users_id' => $existing, 'imported' => false];
                }
                // Rule 2: a dry run stops here.
                if ($dryRun) {
                    return ['users_id' => 0, 'imported' => true];
                }
                $refused = false;
                $res = self::quiet(static fn () => \AuthLDAP::ldapImportUserByServerId(
                    ['method' => $method, 'value' => $value],
                    \AuthLDAP::ACTION_IMPORT,
                    $serverId,
                    false,
                    false,
                    $refused
                ), $failed);
                if (is_array($res) && (int) ($res['id'] ?? 0) > 0) {
                    return ['users_id' => (int) $res['id'], 'imported' => ($res['action'] ?? null) === \AuthLDAP::USER_IMPORTED];
                }
                ($this->logger)(sprintf('LDAP directory %d: found a person but the import %s', $serverId, $refused ? 'was refused by GLPI' : 'failed'));
            }
        }
        return null;
    }

    /**
     * The open connection of a directory, a fresh one when none is open, or when $fresh is set, or after
     * RECONNECT_EVERY searches. null = cannot connect or bind.
     *
     * @return array{ds:mixed,cfg:\AuthLDAP,n:int}|null
     */
    private function connection(int $serverId, bool $fresh = false): ?array
    {
        $c = $this->conn[$serverId] ?? null;
        if ($c !== null && !$fresh && $c['n'] < self::RECONNECT_EVERY) {
            return $c;
        }
        unset($this->conn[$serverId]);
        $cfg = $c['cfg'] ?? new \AuthLDAP();
        if ($c === null && !$cfg->getFromDB($serverId)) {
            return null;
        }
        $ds = self::quiet(static fn () => $cfg->connect(), $failed);
        if (!$ds || $failed) {
            return null;
        }
        return $this->conn[$serverId] = ['ds' => $ds, 'cfg' => $cfg, 'n' => 0];
    }

    /**
     * One search; a failure is answered with ONE fresh connection and a retry before the directory is given up.
     *
     * @param bool $error set true only when the retry on a fresh connection failed too
     * @return array{dn:string, login:string}|null
     */
    private function searchOn(int $serverId, string $method, string $value, bool &$error): ?array
    {
        $c = $this->connection($serverId);
        if ($c === null) {
            $error = true;
            return null;
        }
        $this->conn[$serverId]['n']++;
        $err = false;
        $hit = $this->search($c['ds'], $c['cfg'], $method, $value, $err);
        if (!$err) {
            return $hit;
        }
        $c = $this->connection($serverId, true);
        if ($c === null) {
            $error = true;
            return null;
        }
        $this->conn[$serverId]['n']++;
        $err = false;
        $hit = $this->search($c['ds'], $c['cfg'], $method, $value, $err);
        $error = $err;
        return $err ? null : $hit;
    }

    /**
     * What to try, in order: every e-mail, then the login. Pure.
     *
     * @param list<string> $emails
     * @return list<array{0:string,1:string}> [method, value]
     */
    public static function attempts(array $emails, string $login): array
    {
        $out = [];
        foreach ($emails as $e) {
            $out[] = [\AuthLDAP::IDENTIFIER_EMAIL, $e];
        }
        if ($login !== '') {
            $out[] = [\AuthLDAP::IDENTIFIER_LOGIN, $login];
        }
        return $out;
    }

    /**
     * Run a core call with PHP warnings, notices and exceptions swallowed. $failed is set when anything
     * was raised, so the caller can tell "nothing found" from "could not search".
     *
     * @template T
     * @param callable():T $fn
     * @return T|false
     */
    public static function quiet(callable $fn, ?bool &$failed = null): mixed
    {
        $failed = false;
        set_error_handler(static function (int $no) use (&$failed): bool {
            $failed = true;
            return true; // handled: not logged, not displayed
        });
        try {
            return $fn();
        } catch (\Throwable) {
            $failed = true;
            return false;
        } finally {
            restore_error_handler();
        }
    }

    private function markDown(int $serverId, string $why): void
    {
        $this->down[$serverId] = true;
        ($this->logger)(sprintf('LDAP directory %d: %s, skipped for the rest of the run', $serverId, $why));
    }

    /** Active directory ids, the default one first. @return list<int> */
    private function servers(): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_authldaps', 'WHERE' => ['is_active' => 1], 'ORDER' => ['is_default DESC', 'id ASC']]) as $r) {
            $ids[] = (int) $r['id'];
        }
        return $ids;
    }

    /**
     * @param bool $error set true when the directory could not be searched (as opposed to "no such person")
     * @return array{dn:string, login:string}|null
     */
    private function search($ds, \AuthLDAP $cfg, string $method, string $value, bool &$error): ?array
    {
        $loginField = $cfg->getLdapIdentifierToUse();
        $fields     = [\AuthLDAP::IDENTIFIER_LOGIN => $loginField];
        if ($method === \AuthLDAP::IDENTIFIER_EMAIL) {
            $fields[\AuthLDAP::IDENTIFIER_EMAIL] = $cfg->fields['email1_field'];
        }
        $coreError = null;
        $info = self::quiet(static function () use ($ds, $cfg, $method, $value, $fields, &$coreError) {
            return \AuthLDAP::searchUserDn($ds, [
                'basedn'            => $cfg->fields['basedn'],
                'login_field'       => $fields[$method],
                'search_parameters' => ['method' => $method, 'fields' => $fields],
                'user_params'       => ['method' => $method, 'value' => $value],
                'condition'         => $cfg->fields['condition'],
            ], $coreError);
        }, $raised);
        if ($raised || $coreError === true) {
            $error = true;
            return null;
        }
        if (!is_array($info) || empty($info['dn'])) {
            return null;
        }
        $login = \AuthLDAP::getFieldValue($info, $loginField);
        return ['dn' => (string) $info['dn'], 'login' => (string) $login];
    }

    private function existingUser(string $login, string $dn): int
    {
        $u = new \User();
        if ($login !== '' && $u->getFromDBbySyncField($login) && (int) $u->fields['is_deleted'] === 0) {
            return (int) $u->fields['id'];
        }
        if ($dn !== '' && $u->getFromDBbyDn($dn) && (int) $u->fields['is_deleted'] === 0) {
            return (int) $u->fields['id'];
        }
        return 0;
    }
}
