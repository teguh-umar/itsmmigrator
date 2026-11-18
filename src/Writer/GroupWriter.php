<?php

namespace GlpiPlugin\Itsmmigrator\Writer;

use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalGroup;
use GlpiPlugin\Itsmmigrator\Context;
use GlpiPlugin\Itsmmigrator\MapRepository;
use GlpiPlugin\Itsmmigrator\Matching\VisibleMatcher;

/**
 * Source group -> GLPI Group (spec §4b): match what the target entity can already see (its own
 * groups plus recursive groups of ancestor entities), nearest entity first; create only when
 * nothing matches, in the target entity with is_recursive = 0. A matched group is never modified.
 */
final class GroupWriter
{
    /** @return string matched | created | skipped | failed */
    public function process(ExternalGroup $g, Context $ctx): string
    {
        $mod      = 'groups';
        $existing = MapRepository::find($g->source, 'group', $g->srcId);
        if (MapRepository::decide($existing, $ctx->decideOptions()) === 'skip') {
            $ctx->counters->add($mod, 'skipped');
            return 'skipped';
        }

        $rowId = 0;
        try {
            $groupId = 0;
            $action  = MapRepository::MATCHED;
            $owned   = false; // true when the group is in the target entity (migration-owned scope)

            // Resume / retry: the group was added before the run died.
            if ($existing !== null && (int) $existing['items_id'] > 0 && (string) $existing['itemtype'] === 'Group'
                && countElementsInTable('glpi_groups', ['id' => (int) $existing['items_id']]) > 0) {
                $groupId = (int) $existing['items_id'];
                $action  = (string) $existing['action'];
                $owned   = $this->entityOf($groupId) === $ctx->targetEntity();
                $ctx->log(sprintf('group %s: resuming on GLPI group %d', $g->srcId, $groupId));
            } else {
                $hit = $this->findVisible($g->name, $ctx->targetEntity());
                if ($hit !== null) {
                    $groupId = (int) $hit['id'];
                    $owned   = (int) $hit['entities_id'] === $ctx->targetEntity();
                    $ctx->log(sprintf('group %s matched GLPI group %d in entity %d', $g->srcId, $groupId, (int) $hit['entities_id']));
                }
            }

            if ($groupId === 0) {
                $action = MapRepository::CREATED;
                $owned  = true;
                if (!$ctx->dryRun()) {
                    $groupId = (int) (new \Group())->add([
                        'name'         => $g->name,
                        'comment'      => sprintf('Imported from %s #%s', $g->source, $g->srcId),
                        'entities_id'  => $ctx->targetEntity(),
                        'is_recursive' => 0,
                    ]);
                    if ($groupId <= 0) {
                        throw new \RuntimeException('GLPI refused to create the group');
                    }
                }
            }

            $rowId = Recorder::open($ctx, $g, $existing, 'Group', $groupId, $action);

            // Members: only mapped users, only when the row does not exist, and a group owned by someone
            // else (an ancestor's) only when add_members_to_matched_groups is on (default off).
            if ($groupId > 0 && ($owned || $ctx->option('add_members_to_matched_groups', false))) {
                $this->addMembers($g, $groupId);
            }

            Recorder::ok($ctx, $rowId);
            $ctx->counters->add($mod, $action === MapRepository::CREATED ? 'created' : 'matched');
            return $action === MapRepository::CREATED ? 'created' : 'matched';
        } catch (\Throwable $e) {
            Recorder::fail($ctx, $g, $existing, $rowId, $e->getMessage());
            $ctx->counters->add($mod, 'failed');
            $ctx->log(sprintf('group %s FAILED: %s', $g->srcId, $e->getMessage()));
            return 'failed';
        }
    }

    /** @return array<string,mixed>|null the closest visible group with this name */
    private function findVisible(string $name, int $target): ?array
    {
        global $DB;
        $scope = EntityScope::scope($target);
        $key   = VisibleMatcher::key($name);
        $rows  = [];
        foreach ($DB->request(['FROM' => 'glpi_groups', 'WHERE' => ['entities_id' => $scope]]) as $row) {
            if (VisibleMatcher::key((string) $row['name']) === $key) {
                $rows[] = $row;
            }
        }
        $visible = VisibleMatcher::visible($rows, $target, EntityScope::ancestorsNearestFirst($target));
        return $visible[0] ?? null;
    }

    private function entityOf(int $groupId): int
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['entities_id'], 'FROM' => 'glpi_groups', 'WHERE' => ['id' => $groupId]]) as $row) {
            return (int) $row['entities_id'];
        }
        return -1;
    }

    private function addMembers(ExternalGroup $g, int $groupId): void
    {
        foreach ($g->memberSrcIds as $srcUser) {
            $map = MapRepository::find($g->source, 'user', (string) $srcUser);
            if ($map === null || $map['status'] !== MapRepository::OK || (int) $map['items_id'] <= 0) {
                continue; // an unmapped, skipped or ambiguous user is never added
            }
            $uid = (int) $map['items_id'];
            if (countElementsInTable('glpi_groups_users', ['groups_id' => $groupId, 'users_id' => $uid]) === 0) {
                (new \Group_User())->add(['groups_id' => $groupId, 'users_id' => $uid]);
            }
        }
    }
}
