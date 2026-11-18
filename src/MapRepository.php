<?php

namespace GlpiPlugin\Itsmmigrator;

/**
 * Access to glpi_plugin_itsmmigrator_maps (spec §3, §5). The unique key is (source, src_type, src_id),
 * so a mapping row is the plugin's memory of "this source item is that GLPI item".
 *
 * Rules every caller follows (spec §5): look up first; write the row right after add() with
 * status in_progress; set ok only after the child work is done; never delete rows.
 */
final class MapRepository
{
    public const IN_PROGRESS    = 'in_progress';
    public const OK             = 'ok';
    public const FAILED         = 'failed';
    public const SKIPPED        = 'skipped';
    public const ORPHAN         = 'orphan';
    public const SOURCE_DELETED = 'source_deleted';
    public const NEEDS_REVIEW   = 'needs_review';

    public const CREATED = 'created';
    public const MATCHED = 'matched';
    public const LINKED  = 'linked';

    private const ERROR_MAX = 2000;

    public static function table(): string
    {
        return 'glpi_plugin_itsmmigrator_maps';
    }

    /** @return array<string,mixed>|null */
    public static function find(string $source, string $srcType, string $srcId): ?array
    {
        global $DB;
        foreach ($DB->request([
            'FROM'  => self::table(),
            'WHERE' => ['source' => $source, 'src_type' => $srcType, 'src_id' => $srcId],
            'LIMIT' => 1,
        ]) as $row) {
            return $row;
        }
        return null;
    }

    /**
     * Rows that point at one GLPI item. NOT unique: an FS ticket and an SDP request may share one.
     *
     * @return list<array<string,mixed>>
     */
    public static function findByItem(string $itemtype, int $itemsId): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::table(), 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId]]) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> rows carrying a cross-source key, e.g. freshservice:ticket:12345 */
    public static function findByOrigin(string $originRef): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::table(), 'WHERE' => ['origin_ref' => $originRef]]) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Write the in_progress row for a freshly added (or adopted) GLPI item. If the row already exists
     * (a concurrent runner, a re-run) the existing row wins and is returned untouched.
     *
     * @param array<string,mixed> $extra display_id, origin_ref, content_hash, sync_owned, src_updated_at
     * @return array<string,mixed> the row as stored
     */
    public static function begin(
        int $sourcesId,
        string $source,
        string $srcType,
        string $srcId,
        string $itemtype,
        int $itemsId,
        string $action,
        array $extra = []
    ): array {
        global $DB;

        $existing = self::find($source, $srcType, $srcId);
        if ($existing !== null) {
            return $existing;
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'sources_id'    => $sourcesId,
            'source'        => $source,
            'src_type'      => $srcType,
            'src_id'        => $srcId,
            'itemtype'      => $itemtype,
            'items_id'      => $itemsId,
            'action'        => $action,
            'status'        => self::IN_PROGRESS,
            'date_creation' => $now,
            'date_mod'      => $now,
        ];
        foreach (['display_id', 'origin_ref', 'content_hash', 'sync_owned', 'src_updated_at'] as $k) {
            if (array_key_exists($k, $extra) && $extra[$k] !== null && $extra[$k] !== '') {
                $row[$k] = $extra[$k];
            }
        }

        try {
            $DB->insert(self::table(), $row);
        } catch (\Throwable $e) {
            // Lost a race on the unique key: the other runner's row is the truth.
            $existing = self::find($source, $srcType, $srcId);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }

        return self::find($source, $srcType, $srcId) ?? $row;
    }

    public static function markOk(int $id): void
    {
        self::setStatus($id, self::OK, null);
    }

    public static function markFailed(int $id, string $error): void
    {
        self::setStatus($id, self::FAILED, $error);
    }

    public static function markSkipped(int $id, string $reason): void
    {
        self::setStatus($id, self::SKIPPED, $reason);
    }

    public static function markOrphan(int $id, string $reason): void
    {
        self::setStatus($id, self::ORPHAN, $reason);
    }

    /** Ambiguity is never guessed: the candidates go in $error (spec §4a). */
    public static function markNeedsReview(int $id, string $candidates): void
    {
        self::setStatus($id, self::NEEDS_REVIEW, $candidates);
    }

    /** Point an existing row at a GLPI item (adopting, resolving a review, retrying a failed row). */
    public static function retarget(int $id, string $itemtype, int $itemsId, string $action): void
    {
        global $DB;
        $DB->update(self::table(), [
            'itemtype'     => $itemtype,
            'items_id'     => $itemsId,
            'action'       => $action,
            'status'       => self::IN_PROGRESS,
            'error'        => null,
            'date_mod'     => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    private static function setStatus(int $id, string $status, ?string $error): void
    {
        global $DB;
        $DB->update(self::table(), [
            'status'   => $status,
            'error'    => $error === null ? null : mb_substr($error, 0, self::ERROR_MAX),
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * What the engine does with a source item given its existing row (spec §5 rule 1). Pure.
     *
     * @param array<string,mixed>|null $row
     * @param array{retry_failed?: bool, recreate_missing?: bool} $opts
     * @return string 'create' | 'resume' | 'skip' | 'retry'
     */
    public static function decide(?array $row, array $opts = []): string
    {
        if ($row === null) {
            return 'create';
        }
        return match ((string) ($row['status'] ?? '')) {
            self::IN_PROGRESS => 'resume',
            self::FAILED      => !empty($opts['retry_failed']) ? 'retry' : 'skip',
            self::ORPHAN      => !empty($opts['recreate_missing']) ? 'create' : 'skip',
            default           => 'skip', // ok, skipped, source_deleted, needs_review
        };
    }
}
