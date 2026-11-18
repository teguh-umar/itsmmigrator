<?php

namespace GlpiPlugin\Itsmmigrator\Matching;

/**
 * Step 3 of user matching (spec §4a): look the person up in the directory through GLPI's own LDAP
 * configuration and, when they are not yet a GLPI user, import them as an LDAP user.
 */
interface LdapLookupInterface
{
    /**
     * @param list<string> $emails normalized source e-mails
     * @param string       $login  normalized source login ('' when the source has none)
     * @return array{users_id: int, imported: bool}|null the GLPI user (existing or just imported), null when the
     *         directory does not know the person or no directory could be queried
     */
    public function findAndImport(array $emails, string $login): ?array;
}
