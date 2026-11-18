<?php

namespace GlpiPlugin\Itsmmigrator;

/**
 * The "Needs review" list (spec §4a): source users that could not be matched without guessing.
 * An admin picks the right GLPI user (or says "none"); the mapping is written and later imports use it.
 */
final class NeedsReview
{
    /**
     * Candidate GLPI user ids out of a map row's `error` ("candidates: 7,9"). Pure.
     *
     * @return list<int>
     */
    public static function candidates(?string $error): array
    {
        if ($error === null || !preg_match('/candidates:\s*([0-9,\s]+)/', $error, $m)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $m[1])), static fn (int $i): bool => $i > 0)));
        sort($ids);
        return $ids;
    }

    /** @return list<array<string,mixed>> open review rows, oldest first */
    public static function open(?int $sourcesId = null): array
    {
        global $DB;
        $where = ['status' => MapRepository::NEEDS_REVIEW, 'src_type' => 'user'];
        if ($sourcesId !== null) {
            $where['sources_id'] = $sourcesId;
        }
        $out = [];
        foreach ($DB->request(['FROM' => MapRepository::table(), 'WHERE' => $where, 'ORDER' => 'id']) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Resolve one row.
     *
     * @param int $usersId the GLPI user the admin picked; 0 = "no GLPI user" (the row becomes skipped and tickets use the fallback user)
     * @return array{ok: bool, message: string}
     */
    public static function resolve(int $mapId, int $usersId): array
    {
        global $DB;
        $row = $DB->request(['FROM' => MapRepository::table(), 'WHERE' => ['id' => $mapId]])->current();
        if ($row === null || $row['status'] !== MapRepository::NEEDS_REVIEW || $row['src_type'] !== 'user') {
            return ['ok' => false, 'message' => 'This row is no longer waiting for review.'];
        }
        if ($usersId === 0) {
            MapRepository::markSkipped($mapId, 'resolved by an admin: no GLPI user');
            return ['ok' => true, 'message' => 'Marked as having no GLPI user.'];
        }
        if (countElementsInTable('glpi_users', ['id' => $usersId, 'is_deleted' => 0]) === 0) {
            return ['ok' => false, 'message' => 'That GLPI user does not exist.'];
        }
        // The picked user is not modified; only the mapping changes.
        MapRepository::retarget($mapId, 'User', $usersId, MapRepository::MATCHED);
        MapRepository::markOk($mapId);
        return ['ok' => true, 'message' => 'Mapped. Items imported from now on use this user.'];
    }
}
