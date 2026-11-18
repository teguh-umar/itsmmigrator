<?php

namespace GlpiPlugin\Itsmmigrator\Matching;

/**
 * Step 3 of user matching (spec §4a): look the person up in the directory through GLPI's own LDAP
 * configuration and import them as an LDAP user. The concrete implementation needs a verified
 * AuthLDAP import call and an LDAP server to test against; until then the engine runs without one
 * and logs that the step was skipped.
 */
interface LdapLookupInterface
{
    /**
     * @param list<string> $emails normalized source e-mails
     * @param string       $login  normalized source login ('' when the source has none)
     * @return int|null GLPI user id of the imported (or already imported) LDAP user, null when not found
     */
    public function findAndImport(array $emails, string $login): ?int;
}
