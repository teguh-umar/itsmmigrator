<?php

namespace GlpiPlugin\Itsmmigrator\Writer;

/** Entity-tree lookups shared by the group and category writers (spec §4b). */
final class EntityScope
{
    /**
     * Ancestor entity ids of $entity, nearest first (parent, grandparent, ... root).
     *
     * @return list<int>
     */
    public static function ancestorsNearestFirst(int $entity): array
    {
        global $DB;
        $ids = array_values(array_map('intval', getAncestorsOf('glpi_entities', $entity)));
        if ($ids === []) {
            return [];
        }
        $byLevel = [];
        foreach ($DB->request(['SELECT' => ['id', 'level'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $ids]]) as $row) {
            $byLevel[(int) $row['id']] = (int) $row['level'];
        }
        // Deepest level = nearest to the target.
        uksort($byLevel, static fn (int $a, int $b): int => $byLevel[$b] <=> $byLevel[$a]);
        return array_keys($byLevel);
    }

    /** @return list<int> the target and all its ancestors */
    public static function scope(int $entity): array
    {
        return array_merge([$entity], self::ancestorsNearestFirst($entity));
    }
}
