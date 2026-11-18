<?php

namespace GlpiPlugin\Itsmmigrator\Writer;

use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalUser;
use GlpiPlugin\Itsmmigrator\Context;
use GlpiPlugin\Itsmmigrator\MapRepository;
use GlpiPlugin\Itsmmigrator\Matching\LdapLookupInterface;
use GlpiPlugin\Itsmmigrator\Matching\UserMatcher;

/**
 * Source user -> GLPI user (spec §4a). Matches, never modifies a matched user, never guesses.
 * Order: e-mail (all addresses, alias domains second) -> login -> LDAP lookup -> local user (only
 * with create_missing_users) -> skipped. Ambiguity -> needs_review.
 */
final class UserWriter
{
    private bool $ldapWarned = false;

    public function __construct(private readonly ?LdapLookupInterface $ldap = null)
    {
    }

    /** @return string matched | created | skipped | needs_review | failed (also counted in the context) */
    public function process(ExternalUser $u, Context $ctx): string
    {
        $mod      = 'users';
        $existing = MapRepository::find($u->source, 'user', $u->srcId);
        $decision = MapRepository::decide($existing, $ctx->decideOptions());
        if ($decision === 'skip') {
            $ctx->counters->add($mod, 'skipped');
            return 'skipped';
        }

        $rowId = 0;
        try {
            // Resume / retry: the GLPI user was already added before the run died. Reuse it, never add a second one.
            if ($existing !== null && (int) $existing['items_id'] > 0 && (string) $existing['itemtype'] === 'User'
                && countElementsInTable('glpi_users', ['id' => (int) $existing['items_id'], 'is_deleted' => 0]) > 0) {
                $ctx->log(sprintf('user %s: resuming on GLPI user %d', $u->srcId, (int) $existing['items_id']));
                $this->assignProfile($u, $ctx, (int) $existing['items_id']);
                $rowId = Recorder::open($ctx, $u, $existing, 'User', (int) $existing['items_id'], (string) $existing['action']);
                Recorder::ok($ctx, $rowId);
                $ctx->counters->add($mod, (string) $existing['action'] === MapRepository::CREATED ? 'created' : 'matched');
                return 'matched';
            }

            $emails = UserMatcher::normalizeEmails($u->emails);
            $login  = UserMatcher::normalizeLogin($u->login);

            $emailHits = $this->usersByEmail($emails);
            if ($emailHits === []) {
                $alias = UserMatcher::aliasEmails($emails, (array) $ctx->option('email_aliases', []));
                if ($alias !== []) {
                    $emailHits = $this->usersByEmail($alias);
                    if ($emailHits !== []) {
                        $ctx->log(sprintf('user %s matched through an e-mail alias', $u->srcId));
                    }
                }
            }
            $loginHits = $login !== '' ? $this->usersByLogin($login) : [];
            $verdict   = UserMatcher::decide($emailHits, $loginHits);

            if ($verdict['outcome'] === UserMatcher::NEEDS_REVIEW) {
                $rowId = Recorder::open($ctx, $u, $existing, 'User', 0, MapRepository::MATCHED);
                if ($rowId > 0) {
                    MapRepository::markNeedsReview($rowId, 'candidates: ' . implode(',', $verdict['candidates']));
                }
                $ctx->counters->add($mod, 'needs_review');
                $ctx->log(sprintf('user %s needs review: GLPI candidates %s', $u->srcId, implode(',', $verdict['candidates'])));
                return 'needs_review';
            }

            if ($verdict['outcome'] === UserMatcher::MATCHED) {
                return $this->matched($u, $ctx, $existing, (int) $verdict['users_id'], 'matched');
            }

            // Step 3: the directory.
            if ($ctx->flag('ldap_lookup', true)) {
                if ($this->ldap === null) {
                    if (!$this->ldapWarned) {
                        $ctx->log('LDAP lookup is on but not available in this build: step skipped');
                        $this->ldapWarned = true;
                    }
                } else {
                    $found = $this->ldap->findAndImport($emails, $login);
                    if ($found !== null) {
                        return $this->matched($u, $ctx, $existing, $found, 'matched');
                    }
                }
            }

            // Step 4: a local user, only for accounts that are not in AD.
            if ($ctx->flag('create_missing_users', false) && ($emails !== [] || $login !== '')) {
                return $this->create($u, $ctx, $existing, $emails, $login);
            }

            // Step 5: nothing to attach to. Tickets fall back to the fallback user.
            $rowId = Recorder::open($ctx, $u, $existing, 'User', 0, MapRepository::MATCHED);
            if ($rowId > 0) {
                MapRepository::markSkipped($rowId, 'no GLPI user matches and creation is off');
            }
            $ctx->counters->add($mod, 'skipped');
            return 'skipped';
        } catch (\Throwable $e) {
            Recorder::fail($ctx, $u, $existing, $rowId, $e->getMessage());
            $ctx->counters->add($mod, 'failed');
            $ctx->log(sprintf('user %s FAILED: %s', $u->srcId, $e->getMessage()));
            return 'failed';
        }
    }

    /** @param array<string,mixed>|null $existing */
    private function matched(ExternalUser $u, Context $ctx, ?array $existing, int $usersId, string $action): string
    {
        // The matched user is NEVER modified: no name, e-mail, authtype, password, is_active, entity or group.
        $rowId = Recorder::open($ctx, $u, $existing, 'User', $usersId, $action);
        $this->assignProfile($u, $ctx, $usersId);
        Recorder::ok($ctx, $rowId);
        $ctx->counters->add('users', 'matched');
        return 'matched';
    }

    /**
     * @param array<string,mixed>|null $existing
     * @param list<string> $emails
     */
    private function create(ExternalUser $u, Context $ctx, ?array $existing, array $emails, string $login): string
    {
        $rowId = 0;
        $newId = 0;
        if (!$ctx->dryRun()) {
            $user = new \User();
            $name = $login !== '' ? $login : $emails[0];
            $input = [
                'name'         => $name,
                'realname'     => $u->name,
                'is_active'    => $ctx->flag('activate_created_users', false) ? 1 : 0,
                'comment'      => sprintf('Imported from %s #%s', $u->source, $u->srcId),
                'entities_id'  => $ctx->targetEntity(),
                'is_recursive' => 0,
            ];
            if ($emails !== []) {
                $input['_useremails']    = [-1 => $emails[0]];
                $input['_default_email'] = -1;
            }
            $newId = (int) $user->add($input);
            if ($newId <= 0) {
                throw new \RuntimeException('GLPI refused to create the user');
            }
            // Written before anything else can fail, so a crash never leaves an untracked user (spec §5).
            $rowId = Recorder::open($ctx, $u, $existing, 'User', $newId, MapRepository::CREATED);
            $this->removeProfilesOutsideTarget($newId, $ctx->targetEntity());
        }
        $this->assignProfile($u, $ctx, $newId);
        Recorder::ok($ctx, $rowId);
        $ctx->counters->add('users', 'created');
        return 'created';
    }

    /**
     * Leak guard (spec §4a): rules and default profiles may have given the new user a profile in
     * another entity. Delete every Profile_User of this NEW user outside the target entity.
     */
    private function removeProfilesOutsideTarget(int $usersId, int $target): void
    {
        global $DB;
        $DB->delete('glpi_profiles_users', ['users_id' => $usersId, ['NOT' => ['entities_id' => $target]]]);
    }

    /** Which profile a user gets, from the source's assign_profiles mode. Pure. */
    public static function profileFor(string $mode, bool $isTechnician, int $selfservice, int $tech): int
    {
        return match ($mode) {
            'requester_only' => $isTechnician ? 0 : $selfservice,
            'all'            => $isTechnician ? $tech : $selfservice,
            default          => 0,
        };
    }

    private function assignProfile(ExternalUser $u, Context $ctx, int $usersId): void
    {
        $mode = (string) ($ctx->flag('assign_profiles') ?? $ctx->option('assign_profiles', 'none'));
        $pid  = self::profileFor(
            $mode,
            $u->isTechnician,
            (int) ($ctx->source['selfservice_profiles_id'] ?? 0),
            (int) ($ctx->source['tech_profiles_id'] ?? 0)
        );
        if ($pid <= 0 || $usersId <= 0 || $ctx->dryRun()) {
            return;
        }
        $entity = $ctx->targetEntity();
        // Only this exact (user, profile, entity) row; the user's other profile rows are never touched.
        $pu = new \Profile_User();
        if (countElementsInTable('glpi_profiles_users', ['users_id' => $usersId, 'profiles_id' => $pid, 'entities_id' => $entity]) === 0) {
            $pu->add(['users_id' => $usersId, 'profiles_id' => $pid, 'entities_id' => $entity, 'is_recursive' => 0]);
        }
    }

    /** @param list<string> $emails @return list<int> */
    private function usersByEmail(array $emails): array
    {
        global $DB;
        if ($emails === []) {
            return [];
        }
        $ids = [];
        foreach ($DB->request([
            'SELECT'     => ['glpi_useremails.users_id'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_useremails',
            'INNER JOIN' => ['glpi_users' => ['ON' => ['glpi_useremails' => 'users_id', 'glpi_users' => 'id']]],
            'WHERE'      => ['glpi_useremails.email' => $emails, 'glpi_users.is_deleted' => 0],
        ]) as $row) {
            $ids[] = (int) $row['users_id'];
        }
        return $ids;
    }

    /** @return list<int> */
    private function usersByLogin(string $login): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['name' => $login, 'is_deleted' => 0]]) as $row) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }
}
