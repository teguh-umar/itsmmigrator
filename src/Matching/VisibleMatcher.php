<?php

namespace GlpiPlugin\Itsmmigrator\Matching;

/**
 * Pure rule for groups and categories (spec §4b): match what the target entity can already SEE,
 * not only what is stored in it, and prefer the closest entity.
 */
final class VisibleMatcher
{
    /**
     * Candidates visible from $target: rows stored in the target itself, plus rows in an ancestor
     * entity that are recursive (is_recursive = 1). Closest first: the target, then the nearest ancestor.
     *
     * @param list<array{id:int|string, entities_id:int|string, is_recursive:int|string}> $rows rows that already match by name
     * @param int       $target
     * @param list<int> $ancestorsNearestFirst parent, grandparent, ... root
     * @return list<array<string,mixed>> visible rows, closest entity first (ties keep input order)
     */
    public static function visible(array $rows, int $target, array $ancestorsNearestFirst): array
    {
        $rank = [$target => 0];
        foreach (array_values($ancestorsNearestFirst) as $i => $a) {
            $rank[(int) $a] = $i + 1;
        }

        $kept = [];
        foreach ($rows as $i => $row) {
            $e = (int) $row['entities_id'];
            if (!isset($rank[$e])) {
                continue; // another branch of the tree: never visible
            }
            if ($e !== $target && (int) $row['is_recursive'] !== 1) {
                continue; // an ancestor's row is visible only when it is recursive
            }
            $kept[] = [$rank[$e], $i, $row];
        }
        usort($kept, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $k): array => $k[2], $kept);
    }

    /** Case-insensitive, trimmed comparison key. */
    public static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '');
    }
}
