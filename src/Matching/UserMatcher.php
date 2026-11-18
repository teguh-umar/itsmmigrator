<?php

namespace GlpiPlugin\Itsmmigrator\Matching;

/**
 * Pure user-matching rules (spec §4a). No database: the writer feeds it the GLPI user ids it found.
 */
final class UserMatcher
{
    public const MATCHED      = 'matched';
    public const NEEDS_REVIEW = 'needs_review';
    public const NOT_FOUND    = 'not_found';

    /** Lower-cased, trimmed, empties and duplicates removed. */
    public static function normalizeEmails(array $emails): array
    {
        $out = [];
        foreach ($emails as $e) {
            $e = mb_strtolower(trim((string) $e));
            if ($e !== '' && str_contains($e, '@') && !in_array($e, $out, true)) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /**
     * The same local part on every alias domain, both directions (spec §4a email_aliases).
     * Direct addresses are not repeated here: the caller tries them first.
     *
     * @param list<string> $emails normalized
     * @param list<array{0:string,1:string}> $aliasPairs e.g. [["old.example.co.id","new.example.com"]]
     * @return list<string>
     */
    public static function aliasEmails(array $emails, array $aliasPairs): array
    {
        $out = [];
        foreach ($emails as $e) {
            [$local, $domain] = explode('@', $e, 2) + [1 => ''];
            foreach ($aliasPairs as $pair) {
                $a = mb_strtolower(trim((string) ($pair[0] ?? '')));
                $b = mb_strtolower(trim((string) ($pair[1] ?? '')));
                if ($a === '' || $b === '') {
                    continue;
                }
                foreach ([[$a, $b], [$b, $a]] as [$from, $to]) {
                    if ($domain === $from) {
                        $alt = $local . '@' . $to;
                        if ($alt !== $e && !in_array($alt, $emails, true) && !in_array($alt, $out, true)) {
                            $out[] = $alt;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** SDP `login_name` without a `DOMAIN\` prefix and without an `@domain` suffix, lower-cased. */
    public static function normalizeLogin(?string $login): string
    {
        $l = trim((string) $login);
        if (str_contains($l, '\\')) {
            $l = substr($l, (int) strrpos($l, '\\') + 1);
        }
        if (str_contains($l, '@')) {
            $l = substr($l, 0, (int) strpos($l, '@'));
        }
        return mb_strtolower(trim($l));
    }

    /**
     * Decide from what the database answered. Never guesses.
     *
     * @param list<int> $emailHits GLPI user ids that own one of the source's e-mails
     * @param list<int> $loginHits GLPI user ids whose login equals the source login
     * @return array{outcome: string, users_id: int|null, candidates: list<int>}
     */
    public static function decide(array $emailHits, array $loginHits): array
    {
        $emailHits = array_values(array_unique(array_map('intval', $emailHits)));
        $loginHits = array_values(array_unique(array_map('intval', $loginHits)));

        if (count($emailHits) === 1) {
            // Email and login pointing at different users is a conflict, not a tie to break.
            if ($loginHits !== [] && $loginHits !== $emailHits) {
                return self::review(array_merge($emailHits, $loginHits));
            }
            return ['outcome' => self::MATCHED, 'users_id' => $emailHits[0], 'candidates' => $emailHits];
        }
        if (count($emailHits) > 1) {
            return self::review(array_merge($emailHits, $loginHits));
        }
        if (count($loginHits) === 1) {
            return ['outcome' => self::MATCHED, 'users_id' => $loginHits[0], 'candidates' => $loginHits];
        }
        if (count($loginHits) > 1) {
            return self::review($loginHits);
        }
        return ['outcome' => self::NOT_FOUND, 'users_id' => null, 'candidates' => []];
    }

    /** @return array{outcome: string, users_id: null, candidates: list<int>} */
    private static function review(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return ['outcome' => self::NEEDS_REVIEW, 'users_id' => null, 'candidates' => $ids];
    }
}
