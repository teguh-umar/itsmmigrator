<?php

namespace GlpiPlugin\Itsmmigrator;

use GlpiPlugin\Itsmmigrator\Matching\VisibleMatcher;
use GlpiPlugin\Itsmmigrator\Writer\EntityScope;

/**
 * Read-only consistency checks of everything the plugin migrated (spec §10 `verify`, §13).
 * Nothing here writes. Each check returns a list of findings; an empty list means "clean".
 * A finding names the map row or GLPI item, never user data (no e-mail, no ticket text).
 */
final class Verifier
{
    /** Findings listed per check; the rest is only counted. */
    public const LIST_LIMIT = 20;

    /** itemtype -> does the table have an entities_id / is_deleted column (checked once). @var array<string,array{0:bool,1:bool,2:bool}> */
    private array $cols = [];

    /**
     * @param int|null $sourcesId limit to one source (null = all)
     * @return array<string, array{title:string, findings:list<string>, total:int}> check id => result
     */
    public function run(?int $sourcesId = null): array
    {
        $checks = [
            'missing'      => ['Mapped GLPI items that are missing, trashed or moved out of the target entity', $this->missing($sourcesId)],
            'unfinished'   => ['Map rows that are in_progress, needs_review or failed', $this->unfinished($sourcesId)],
            'dup_users'    => ['GLPI users that share an e-mail address', $this->duplicateUsers()],
            'shadow'       => ['Groups or categories the plugin created although a same-named visible one exists in a parent entity', $this->shadowed($sourcesId)],
            'dup_titles'   => ['The same [FS#id] / [SDP#id] ticket more than once in one entity', $this->duplicateTitles()],
            'dup_origin'   => ['Several GLPI tickets for one origin_ref', $this->duplicateOrigins($sourcesId)],
            'dup_docs'     => ['A document linked twice to one ticket, or one file stored twice in one entity', $this->duplicateDocuments($sourcesId)],
            'isolation'    => ['Created items outside the source\'s target entity, recursive, or users with profiles outside it', $this->isolation($sourcesId)],
            'sync_owned'   => ['Sync-owned tickets edited outside the sync', $this->syncOwnedEdits($sourcesId)],
        ];
        $out = [];
        foreach ($checks as $id => [$title, $all]) {
            $out[$id] = ['title' => $title, 'findings' => array_slice($all, 0, self::LIST_LIMIT), 'total' => count($all)];
        }
        return $out;
    }

    /** @param array<string, array{total:int}> $result */
    public static function total(array $result): int
    {
        return array_sum(array_map(static fn (array $r): int => $r['total'], $result));
    }

    // ---------------------------------------------------------------- checks

    /** @return list<string> */
    private function missing(?int $sourcesId): array
    {
        global $DB;
        $out = [];
        $sources = $this->sourceEntities();

        $byType = [];
        foreach ($DB->request(['FROM' => MapRepository::table(), 'WHERE' => $this->scope($sourcesId) + [['NOT' => ['itemtype' => '']], ['items_id' => ['>', 0]]]]) as $row) {
            if (in_array($row['status'], [MapRepository::ORPHAN, MapRepository::SOURCE_DELETED, MapRepository::SKIPPED], true)) {
                continue;
            }
            $byType[(string) $row['itemtype']][] = $row;
        }

        foreach ($byType as $itemtype => $rows) {
            $table = getTableForItemType($itemtype);
            if (!$DB->tableExists($table)) {
                continue;
            }
            [$hasEntity, $hasDeleted] = $this->columns($table);
            $found = [];
            foreach (array_chunk(array_unique(array_map(static fn ($r) => (int) $r['items_id'], $rows)), 500) as $ids) {
                $select = ['id'];
                if ($hasEntity) {
                    $select[] = 'entities_id';
                }
                if ($hasDeleted) {
                    $select[] = 'is_deleted';
                }
                foreach ($DB->request(['SELECT' => $select, 'FROM' => $table, 'WHERE' => ['id' => $ids]]) as $g) {
                    $found[(int) $g['id']] = $g;
                }
            }
            foreach ($rows as $row) {
                $id = (int) $row['items_id'];
                $tag = sprintf('map #%d (%s %s) -> %s #%d', $row['id'], $row['src_type'], $row['src_id'], $itemtype, $id);
                if (!isset($found[$id])) {
                    $out[] = $tag . ': missing in GLPI';
                    continue;
                }
                if ($hasDeleted && (int) $found[$id]['is_deleted'] === 1) {
                    $out[] = $tag . ': in the trash';
                }
                // Only what the plugin CREATED must stay in the target entity: a matched or linked item belongs to someone else.
                if ($hasEntity && $row['action'] === MapRepository::CREATED
                    && isset($sources[(int) $row['sources_id']]) && (int) $found[$id]['entities_id'] !== $sources[(int) $row['sources_id']]) {
                    $out[] = $tag . sprintf(': moved to entity %d (target is %d)', (int) $found[$id]['entities_id'], $sources[(int) $row['sources_id']]);
                }
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function unfinished(?int $sourcesId): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => MapRepository::table(), 'WHERE' => $this->scope($sourcesId) + ['status' => [MapRepository::IN_PROGRESS, MapRepository::NEEDS_REVIEW, MapRepository::FAILED]], 'ORDER' => 'id']) as $r) {
            $out[] = sprintf('map #%d (%s %s): %s%s', $r['id'], $r['src_type'], $r['src_id'], $r['status'], $r['error'] ? ' - ' . mb_substr((string) $r['error'], 0, 120) : '');
        }
        return $out;
    }

    /** @return list<string> user ids only, never the address */
    private function duplicateUsers(): array
    {
        global $DB;
        $by = [];
        foreach ($DB->request([
            'SELECT'     => ['glpi_useremails.email', 'glpi_useremails.users_id'],
            'FROM'       => 'glpi_useremails',
            'INNER JOIN' => ['glpi_users' => ['ON' => ['glpi_useremails' => 'users_id', 'glpi_users' => 'id']]],
            'WHERE'      => ['glpi_users.is_deleted' => 0, ['NOT' => ['glpi_useremails.email' => '']]],
        ]) as $r) {
            $by[mb_strtolower(trim((string) $r['email']))][(int) $r['users_id']] = true;
        }
        $out = [];
        foreach ($by as $users) {
            if (count($users) > 1) {
                $ids = array_keys($users);
                sort($ids);
                $out[] = 'users ' . implode(', ', $ids) . ' share one e-mail address';
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function shadowed(?int $sourcesId): array
    {
        global $DB;
        $out     = [];
        $sources = $this->sourceEntities();
        $kinds   = ['Group' => ['glpi_groups', 'name'], 'ITILCategory' => ['glpi_itilcategories', 'completename']];

        foreach ($DB->request(['FROM' => MapRepository::table(), 'WHERE' => $this->scope($sourcesId) + ['action' => MapRepository::CREATED, 'itemtype' => array_keys($kinds), ['items_id' => ['>', 0]]]]) as $row) {
            [$table, $nameField] = $kinds[$row['itemtype']];
            $mine = $DB->request(['FROM' => $table, 'WHERE' => ['id' => (int) $row['items_id']]])->current();
            if ($mine === null) {
                continue;
            }
            $target    = (int) $mine['entities_id'];
            $ancestors = EntityScope::ancestorsNearestFirst($target);
            if ($ancestors === []) {
                continue;
            }
            $key  = VisibleMatcher::key((string) $mine[$nameField]);
            $rows = [];
            foreach ($DB->request(['FROM' => $table, 'WHERE' => ['entities_id' => $ancestors, ['NOT' => ['id' => (int) $row['items_id']]]]]) as $c) {
                if (VisibleMatcher::key((string) $c[$nameField]) === $key) {
                    $rows[] = $c;
                }
            }
            $visible = VisibleMatcher::visible($rows, $target, $ancestors);
            if ($visible !== []) {
                $out[] = sprintf('map #%d: created %s #%d has the same name as visible %s #%d in entity %d', $row['id'], $row['itemtype'], $row['items_id'], $row['itemtype'], $visible[0]['id'], $visible[0]['entities_id']);
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function duplicateTitles(): array
    {
        global $DB;
        $seen = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'entities_id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['is_deleted' => 0, 'OR' => [['name' => ['LIKE', '[FS#%']], ['name' => ['LIKE', '[SDP#%']]]]]) as $t) {
            if (preg_match('/^\[(FS|SDP)#([^\]]+)\]/', (string) $t['name'], $m)) {
                $seen[(int) $t['entities_id'] . '|' . $m[1] . '#' . $m[2]][] = (int) $t['id'];
            }
        }
        $out = [];
        foreach ($seen as $key => $ids) {
            if (count($ids) > 1) {
                [$entity, $tag] = explode('|', $key, 2);
                $out[] = sprintf('[%s] appears on GLPI tickets %s in entity %s', $tag, implode(', ', $ids), $entity);
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function duplicateOrigins(?int $sourcesId): array
    {
        global $DB;
        $by = [];
        foreach ($DB->request(['FROM' => MapRepository::table(), 'WHERE' => $this->scope($sourcesId) + ['itemtype' => 'Ticket', ['NOT' => ['origin_ref' => null]], ['items_id' => ['>', 0]]]]) as $r) {
            $by[(string) $r['origin_ref']][(int) $r['items_id']] = true;
        }
        $out = [];
        foreach ($by as $ref => $tickets) {
            if (count($tickets) > 1) {
                $out[] = sprintf('origin_ref %s points at GLPI tickets %s', $ref, implode(', ', array_keys($tickets)));
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function duplicateDocuments(?int $sourcesId): array
    {
        global $DB;
        $out = [];
        $docIds = [];
        foreach ($DB->request(['FROM' => MapRepository::table(), 'WHERE' => $this->scope($sourcesId) + ['src_type' => 'document', ['items_id' => ['>', 0]]]]) as $r) {
            $docIds[(int) $r['items_id']] = true;
        }
        if ($docIds === []) {
            return [];
        }
        $links = [];
        foreach ($DB->request(['SELECT' => ['documents_id', 'itemtype', 'items_id'], 'FROM' => 'glpi_documents_items', 'WHERE' => ['documents_id' => array_keys($docIds)]]) as $l) {
            $links[$l['documents_id'] . '|' . $l['itemtype'] . '|' . $l['items_id']] = ($links[$l['documents_id'] . '|' . $l['itemtype'] . '|' . $l['items_id']] ?? 0) + 1;
        }
        foreach ($links as $key => $n) {
            if ($n > 1) {
                [$doc, $type, $item] = explode('|', $key);
                $out[] = sprintf('document #%s is linked %d times to %s #%s', $doc, $n, $type, $item);
            }
        }
        $bySha = [];
        foreach ($DB->request(['SELECT' => ['id', 'sha1sum', 'entities_id'], 'FROM' => 'glpi_documents', 'WHERE' => ['id' => array_keys($docIds), 'is_deleted' => 0, ['NOT' => ['sha1sum' => null]]]]) as $d) {
            $bySha[$d['entities_id'] . '|' . $d['sha1sum']][] = (int) $d['id'];
        }
        foreach ($bySha as $key => $ids) {
            if (count($ids) > 1) {
                $out[] = sprintf('documents %s hold the same file in entity %s', implode(', ', $ids), explode('|', $key)[0]);
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function isolation(?int $sourcesId): array
    {
        global $DB;
        $out     = [];
        $sources = $this->sourceEntities();
        $createdUsers = [];

        foreach ($DB->request(['FROM' => MapRepository::table(), 'WHERE' => $this->scope($sourcesId) + ['action' => MapRepository::CREATED, ['items_id' => ['>', 0]], ['NOT' => ['itemtype' => '']]]]) as $row) {
            $target = $sources[(int) $row['sources_id']] ?? null;
            $table  = getTableForItemType((string) $row['itemtype']);
            if ($target === null || !$DB->tableExists($table)) {
                continue;
            }
            if ($row['itemtype'] === 'User') {
                $createdUsers[(int) $row['items_id']] = $target;
                continue;
            }
            [$hasEntity] = $this->columns($table);
            if (!$hasEntity) {
                continue;
            }
            $item = $DB->request(['FROM' => $table, 'WHERE' => ['id' => (int) $row['items_id']]])->current();
            if ($item === null) {
                continue;
            }
            $tag = sprintf('map #%d: %s #%d', $row['id'], $row['itemtype'], $row['items_id']);
            if ((int) $item['entities_id'] !== $target) {
                $out[] = $tag . sprintf(' is in entity %d, not the target %d', (int) $item['entities_id'], $target);
            }
            if ((int) ($item['is_recursive'] ?? 0) === 1) {
                $out[] = $tag . ' is recursive';
            }
        }
        foreach ($createdUsers as $uid => $target) {
            $n = countElementsInTable('glpi_profiles_users', ['users_id' => $uid, ['NOT' => ['entities_id' => $target]]]);
            if ($n > 0) {
                $out[] = sprintf('created user #%d holds %d profile(s) outside the target entity %d', $uid, $n, $target);
            }
        }
        return $out;
    }

    /**
     * Sync-owned tickets edited outside the sync (spec §10). Sync-owned rows only appear with the
     * ServiceDesk Plus adapter (M4); until then there is nothing to check and the answer is "clean".
     *
     * @return list<string>
     */
    private function syncOwnedEdits(?int $sourcesId): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => MapRepository::table(), 'WHERE' => $this->scope($sourcesId) + ['sync_owned' => 1, 'itemtype' => 'Ticket', ['items_id' => ['>', 0]]]]) as $r) {
            $t = $DB->request(['SELECT' => ['date_mod'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => (int) $r['items_id']]])->current();
            if ($t !== null && $r['date_mod'] !== null && strtotime((string) $t['date_mod']) > strtotime((string) $r['date_mod']) + 60) {
                $out[] = sprintf('map #%d: GLPI ticket #%d was modified after the last sync of it', $r['id'], $r['items_id']);
            }
        }
        return $out;
    }

    // --------------------------------------------------------------- helpers

    /** @return array<string,mixed> */
    private function scope(?int $sourcesId): array
    {
        return $sourcesId === null ? [] : ['sources_id' => $sourcesId];
    }

    /** @return array<int,int> sources.id => target entity */
    private function sourceEntities(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['SELECT' => ['id', 'entities_id'], 'FROM' => Source::getTable()]) as $r) {
            $out[(int) $r['id']] = (int) $r['entities_id'];
        }
        return $out;
    }

    /** @return array{0:bool,1:bool} has entities_id, has is_deleted */
    private function columns(string $table): array
    {
        global $DB;
        return $this->cols[$table] ??= [$DB->fieldExists($table, 'entities_id'), $DB->fieldExists($table, 'is_deleted')];
    }
}
