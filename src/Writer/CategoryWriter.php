<?php

namespace GlpiPlugin\Itsmmigrator\Writer;

use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalCategory;
use GlpiPlugin\Itsmmigrator\Context;
use GlpiPlugin\Itsmmigrator\MapRepository;
use GlpiPlugin\Itsmmigrator\Matching\VisibleMatcher;

/**
 * Source category (a tree path) -> GLPI ITILCategory (spec §4b). Each level is matched by its full
 * `completename` among the categories visible from the target entity, nearest entity first; a missing
 * level is created in the target entity (is_recursive = 0) under the level above it.
 */
final class CategoryWriter
{
    /** @return string matched | created | skipped | failed */
    public function process(ExternalCategory $c, Context $ctx): string
    {
        $mod      = 'categories';
        $existing = MapRepository::find($c->source, 'itilcategory', $c->srcId);
        if (MapRepository::decide($existing, $ctx->decideOptions()) === 'skip') {
            $ctx->counters->add($mod, 'skipped');
            return 'skipped';
        }
        if ($c->path === []) {
            $ctx->counters->add($mod, 'failed');
            $ctx->log(sprintf('category %s FAILED: empty path', $c->srcId));
            return 'failed';
        }

        $rowId = 0;
        try {
            $leaf   = 0;
            $action = MapRepository::MATCHED;

            if ($existing !== null && (int) $existing['items_id'] > 0 && (string) $existing['itemtype'] === 'ITILCategory'
                && countElementsInTable('glpi_itilcategories', ['id' => (int) $existing['items_id']]) > 0) {
                $leaf   = (int) $existing['items_id'];
                $action = (string) $existing['action'];
                $ctx->log(sprintf('category %s: resuming on GLPI category %d', $c->srcId, $leaf));
            } else {
                [$leaf, $created] = $this->resolvePath($c, $ctx);
                $action = $created ? MapRepository::CREATED : MapRepository::MATCHED;
            }

            // The leaf is mapped right after it exists (spec §5 rule 2).
            $rowId = Recorder::open($ctx, $c, $existing, 'ITILCategory', $leaf, $action);
            Recorder::ok($ctx, $rowId);
            $ctx->counters->add($mod, $action === MapRepository::CREATED ? 'created' : 'matched');
            return $action === MapRepository::CREATED ? 'created' : 'matched';
        } catch (\Throwable $e) {
            Recorder::fail($ctx, $c, $existing, $rowId, $e->getMessage());
            $ctx->counters->add($mod, 'failed');
            $ctx->log(sprintf('category %s FAILED: %s', $c->srcId, $e->getMessage()));
            return 'failed';
        }
    }

    /**
     * Walk the path, matching or creating each level.
     *
     * @return array{0:int,1:bool} [leaf id (0 in a dry run that would create), whether the LEAF was created]
     */
    private function resolvePath(ExternalCategory $c, Context $ctx): array
    {
        $target  = $ctx->targetEntity();
        $parent  = 0;
        $names   = [];
        $created = false;
        $last    = count($c->path) - 1;

        foreach ($c->path as $i => $name) {
            $names[] = trim((string) $name);
            $hit = $this->findVisible(implode(' > ', $names), $target);
            if ($hit !== null) {
                $parent  = (int) $hit['id'];
                $created = false;
                continue;
            }

            $created = true;
            if ($ctx->dryRun()) {
                $parent = 0;
                continue;
            }
            $parent = (int) (new \ITILCategory())->add([
                'name'               => trim((string) $name),
                'itilcategories_id'  => $parent,
                'comment'            => $i === $last
                    ? sprintf('Imported from %s #%s', $c->source, $c->srcId)
                    : sprintf('Imported from %s (parent of #%s)', $c->source, $c->srcId),
                'entities_id'        => $target,
                'is_recursive'       => 0,
            ]);
            if ($parent <= 0) {
                throw new \RuntimeException('GLPI refused to create the category "' . implode(' > ', $names) . '"');
            }
        }

        return [$parent, $created];
    }

    /** @return array<string,mixed>|null */
    private function findVisible(string $completename, int $target): ?array
    {
        global $DB;
        $key  = VisibleMatcher::key($completename);
        $rows = [];
        foreach ($DB->request(['FROM' => 'glpi_itilcategories', 'WHERE' => ['entities_id' => EntityScope::scope($target)]]) as $row) {
            if (VisibleMatcher::key((string) $row['completename']) === $key) {
                $rows[] = $row;
            }
        }
        $visible = VisibleMatcher::visible($rows, $target, EntityScope::ancestorsNearestFirst($target));
        return $visible[0] ?? null;
    }
}
