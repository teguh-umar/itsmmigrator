<?php

namespace GlpiPlugin\Itsmmigrator\Writer;

use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalItem;
use GlpiPlugin\Itsmmigrator\Context;
use GlpiPlugin\Itsmmigrator\MapRepository;

/**
 * The one place writers record the outcome of an item in the maps table, so the rules of spec §5
 * (write the row right after add(), status in_progress first, never in a dry run) live once.
 */
final class Recorder
{
    /**
     * Open or re-open the row for $item pointing at ($itemtype, $itemsId). Returns the row id, or 0 in a
     * dry run (nothing is written).
     *
     * @param array<string,mixed>|null $existing the row found before, if any
     * @param array<string,mixed> $extra display_id, origin_ref, content_hash, sync_owned, src_updated_at
     */
    public static function open(
        Context $ctx,
        ExternalItem $item,
        ?array $existing,
        string $itemtype,
        int $itemsId,
        string $action,
        array $extra = []
    ): int {
        if ($ctx->dryRun()) {
            return 0;
        }
        if ($existing !== null) {
            MapRepository::retarget((int) $existing['id'], $itemtype, $itemsId, $action);
            return (int) $existing['id'];
        }
        $extra += [
            'display_id'     => $item->displayId,
            'origin_ref'     => $item->originRef,
            'src_updated_at' => $item->updatedAt?->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'),
        ];
        $row = MapRepository::begin($ctx->sourcesId(), $item->source, $item->srcType, $item->srcId, $itemtype, $itemsId, $action, $extra);
        return (int) $row['id'];
    }

    public static function ok(Context $ctx, int $rowId): void
    {
        if (!$ctx->dryRun() && $rowId > 0) {
            MapRepository::markOk($rowId);
        }
    }

    public static function failed(Context $ctx, int $rowId, string $error): void
    {
        if (!$ctx->dryRun() && $rowId > 0) {
            MapRepository::markFailed($rowId, $error);
        }
    }

    /**
     * Record a failure for an item whose row may not exist yet (add() itself threw). An existing row
     * keeps its items_id so a retry reuses it (spec §5 rule 1).
     *
     * @param array<string,mixed>|null $existing
     */
    public static function fail(Context $ctx, ExternalItem $item, ?array $existing, int $rowId, string $error): void
    {
        if ($ctx->dryRun()) {
            return;
        }
        if ($rowId <= 0 && $existing !== null) {
            $rowId = (int) $existing['id'];
        }
        if ($rowId <= 0) {
            $rowId = (int) MapRepository::begin($ctx->sourcesId(), $item->source, $item->srcType, $item->srcId, '', 0, MapRepository::CREATED)['id'];
        }
        MapRepository::markFailed($rowId, $error);
    }
}
