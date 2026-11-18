<?php

namespace GlpiPlugin\Itsmmigrator\Writer;

use GlpiPlugin\Itsmmigrator\Context;
use GlpiPlugin\Itsmmigrator\MapRepository;

/** Resolve a source user reference to a GLPI user id through the map (0 when unmapped / ambiguous / skipped). */
final class UserRef
{
    public static function resolve(string $source, ?string $srcId, Context $ctx): int
    {
        if ($srcId === null || $srcId === '') {
            return 0;
        }
        // A bare id (a note author) can be an agent or a requester: try the requester first.
        $candidates = str_contains($srcId, ':') ? [$srcId] : ['requester:' . $srcId, 'agent:' . $srcId, $srcId];
        foreach ($candidates as $c) {
            $row = MapRepository::find($source, 'user', $c);
            if ($row !== null && $row['status'] === MapRepository::OK && (int) $row['items_id'] > 0) {
                return (int) $row['items_id'];
            }
        }
        return (int) $ctx->flag('fallback_user', 0);
    }

    public static function group(string $source, ?string $srcId): int
    {
        if ($srcId === null || $srcId === '') {
            return 0;
        }
        $row = MapRepository::find($source, 'group', $srcId);
        return $row !== null && $row['status'] === MapRepository::OK ? (int) $row['items_id'] : 0;
    }

    /** @param list<string> $path */
    public static function category(string $source, array $path): int
    {
        if ($path === []) {
            return 0;
        }
        $row = MapRepository::find($source, 'itilcategory', implode(' > ', $path));
        return $row !== null && $row['status'] === MapRepository::OK ? (int) $row['items_id'] : 0;
    }
}
