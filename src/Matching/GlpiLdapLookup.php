<?php

namespace GlpiPlugin\Itsmmigrator\Matching;

/**
 * Directory lookup through GLPI's own AuthLDAP (verified in 11.0.13: `AuthLDAP::searchUserDn()` and
 * `AuthLDAP::ldapImportUserByServerId()`).
 *
 * Two safety rules:
 *  1. The search is read-only. A person who is already a GLPI user (by sync field or DN) is returned
 *     as is and NEVER passed to the import call, because core's import of an existing inactive user
 *     "restores" (re-activates) it, and a matched user must never be modified (spec §4a).
 *  2. Only active directories are queried, default one first; a directory that cannot be reached or
 *     bound is skipped, never fatal. No host name, base DN or user data is logged.
 */
final class GlpiLdapLookup implements LdapLookupInterface
{
    /** @var callable(string):void */
    private $logger;

    /** @var array<int,true> directories that failed to connect or bind: not retried for every user of the run */
    private array $down = [];

    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger ?? static function (string $m): void {
        };
    }

    public function findAndImport(array $emails, string $login): ?array
    {
        foreach ($this->servers() as $serverId) {
            if (isset($this->down[$serverId])) {
                continue;
            }
            $cfg = new \AuthLDAP();
            if (!$cfg->getFromDB($serverId)) {
                continue;
            }
            try {
                $ds = @$cfg->connect();
            } catch (\Throwable) {
                $ds = false;
            }
            if (!$ds) {
                $this->down[$serverId] = true;
                ($this->logger)(sprintf('LDAP directory %d: not reachable or bind refused, skipped for the rest of the run', $serverId));
                continue;
            }

            foreach (self::attempts($emails, $login) as [$method, $value]) {
                $hit = $this->search($ds, $cfg, $method, $value);
                if ($hit === null) {
                    continue;
                }
                // Rule 1: already a GLPI user -> hand it back untouched.
                $existing = $this->existingUser($hit['login'], $hit['dn']);
                if ($existing > 0) {
                    return ['users_id' => $existing, 'imported' => false];
                }
                $refused = false;
                $res = \AuthLDAP::ldapImportUserByServerId(
                    ['method' => $method, 'value' => $value],
                    \AuthLDAP::ACTION_IMPORT,
                    $serverId,
                    false,
                    false,
                    $refused
                );
                if (is_array($res) && (int) ($res['id'] ?? 0) > 0) {
                    return ['users_id' => (int) $res['id'], 'imported' => ($res['action'] ?? null) === \AuthLDAP::USER_IMPORTED];
                }
                ($this->logger)(sprintf('LDAP directory %d: found a person but the import %s', $serverId, $refused ? 'was refused by GLPI' : 'failed'));
            }
        }
        return null;
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

    /** @return array{dn:string, login:string}|null */
    private function search($ds, \AuthLDAP $cfg, string $method, string $value): ?array
    {
        $loginField = $cfg->getLdapIdentifierToUse();
        $fields     = [\AuthLDAP::IDENTIFIER_LOGIN => $loginField];
        if ($method === \AuthLDAP::IDENTIFIER_EMAIL) {
            $fields[\AuthLDAP::IDENTIFIER_EMAIL] = $cfg->fields['email1_field'];
        }
        try {
            $error = null;
            $info  = \AuthLDAP::searchUserDn($ds, [
                'basedn'            => $cfg->fields['basedn'],
                'login_field'       => $fields[$method],
                'search_parameters' => ['method' => $method, 'fields' => $fields],
                'user_params'       => ['method' => $method, 'value' => $value],
                'condition'         => $cfg->fields['condition'],
            ], $error);
        } catch (\Throwable) {
            return null;
        }
        if ($error === true || !is_array($info) || empty($info['dn'])) {
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
