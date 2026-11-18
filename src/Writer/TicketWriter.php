<?php

namespace GlpiPlugin\Itsmmigrator\Writer;

use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalAttachment;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalNote;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalTask;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalTicket;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalWorklog;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\TicketChildren;
use GlpiPlugin\Itsmmigrator\Adapter\SourceAdapterInterface;
use GlpiPlugin\Itsmmigrator\Context;
use GlpiPlugin\Itsmmigrator\MapRepository;
use GlpiPlugin\Itsmmigrator\Mapping;

/**
 * Source ticket -> GLPI Ticket with followups, tasks, worklogs, solution and documents (spec §4, §5, §6).
 *
 * Order and safety, per ticket:
 *  1. map lookup (ok/skipped -> skip, in_progress -> resume the SAME GLPI ticket, failed -> --retry-failed)
 *  2. cross-source link by origin_ref (an SDP-created ticket for this FS id) -> link, create nothing
 *  3. natural key `[FS#id]` in the target entity -> adopt
 *  4. add the ticket in the target entity, write the map row at once (in_progress)
 *  5. every child is mapped on its own, so a re-run never duplicates one
 *  6. status and dates are written LAST with $DB->update() so GLPI does not recalculate them
 *  7. the ticket row becomes ok only when every child succeeded; otherwise failed (children keep their rows)
 */
final class TicketWriter
{
    /** Set at the start of process(): attachments need the adapter to download. */
    private ?SourceAdapterInterface $adapterRef = null;

    /** @return string created | matched | linked | skipped | failed */
    public function process(ExternalTicket $t, SourceAdapterInterface $adapter, Context $ctx): string
    {
        $mod      = 'tickets';
        $this->adapterRef = $adapter;
        $prefix   = $t->source === 'freshservice' ? 'FS' : 'SDP';
        $existing = MapRepository::find($t->source, 'ticket', $t->srcId);
        $decision = MapRepository::decide($existing, $ctx->decideOptions());
        if ($decision === 'skip') {
            $ctx->counters->add($mod, 'skipped');
            return 'skipped';
        }

        $rowId = 0;
        try {
            // 2. Another source already created this real-world ticket: link, never duplicate (spec §6.2).
            if ($existing === null || (int) $existing['items_id'] === 0) {
                $linked = $this->findLinkTarget($t);
                if ($linked > 0) {
                    $rowId = Recorder::open($ctx, $t, $existing, 'Ticket', $linked, MapRepository::LINKED);
                    Recorder::ok($ctx, $rowId);
                    $ctx->counters->add($mod, 'linked');
                    $ctx->log(sprintf('ticket %s linked to GLPI ticket %d (created from another source)', $t->srcId, $linked));
                    return 'linked';
                }
            }

            $ticketId = 0;
            $action   = MapRepository::CREATED;
            if ($existing !== null && (int) $existing['items_id'] > 0 && (string) $existing['itemtype'] === 'Ticket'
                && countElementsInTable('glpi_tickets', ['id' => (int) $existing['items_id'], 'is_deleted' => 0]) > 0) {
                $ticketId = (int) $existing['items_id'];
                $action   = (string) $existing['action'];
                $ctx->log(sprintf('ticket %s: resuming on GLPI ticket %d', $t->srcId, $ticketId));
            } else {
                // 3. Natural key.
                $found = $this->findByTitlePrefix($prefix, $t->displayId ?? $t->srcId, $ctx->targetEntity());
                if ($found > 0) {
                    $ticketId = $found;
                    $action   = MapRepository::MATCHED;
                    $ctx->log(sprintf('ticket %s: adopted GLPI ticket %d by its [%s#%s] title', $t->srcId, $found, $prefix, $t->displayId ?? $t->srcId));
                }
            }

            if ($ctx->dryRun()) {
                $ctx->counters->add($mod, $ticketId > 0 ? 'matched' : 'created');
                $ctx->log(sprintf('[dry] ticket %s "%s": would %s', $t->srcId, $t->subject, $ticketId > 0 ? 'reuse GLPI ticket ' . $ticketId : 'create'));
                return $ticketId > 0 ? 'matched' : 'created';
            }

            if ($ticketId === 0) {
                $ticketId = $this->add($t, $prefix, $ctx);
            }
            // 4. Mapped right after add(): a crash from here on resumes instead of re-adding.
            $rowId = Recorder::open($ctx, $t, $existing, 'Ticket', $ticketId, $action);

            // 5. Children, each mapped on its own.
            $children = $adapter->fetchTicketChildren($t->srcId);
            $errors   = $this->children($t, $children, $ticketId, $ctx);

            // 6. Status and dates last.
            $this->finalize($t, $children, $ticketId, $ctx);

            // 7.
            if ($errors !== []) {
                Recorder::failed($ctx, $rowId, implode(' | ', array_slice($errors, 0, 5)));
                $ctx->counters->add($mod, 'failed');
                return 'failed';
            }
            Recorder::ok($ctx, $rowId);
            $ctx->counters->add($mod, $action === MapRepository::CREATED ? 'created' : 'matched');
            return $action === MapRepository::CREATED ? 'created' : 'matched';
        } catch (\Throwable $e) {
            Recorder::fail($ctx, $t, $existing, $rowId, $e->getMessage());
            $ctx->counters->add($mod, 'failed');
            $ctx->log(sprintf('ticket %s FAILED: %s', $t->srcId, $e->getMessage()));
            return 'failed';
        }
    }

    // ------------------------------------------------------------ creation

    private function add(ExternalTicket $t, string $prefix, Context $ctx): int
    {
        $status   = Mapping::status($t->status, $t->assigneeSrcId !== null, $this->jsonOpt($ctx, 'status_map'));
        $priority = Mapping::priority($t->priority, $this->jsonOpt($ctx, 'priority_map'));
        $type     = Mapping::type($t->type, $this->jsonOpt($ctx, 'type_map'));
        foreach ([['status', $t->status, $status], ['priority', $t->priority, $priority], ['type', $t->type, $type]] as [$what, $label, $m]) {
            if (!$m['known']) {
                $ctx->log(sprintf('ticket %s: unmapped %s "%s", default used', $t->srcId, $what, $label));
            }
        }

        $requester = UserRef::resolve($t->source, $t->requesterSrcId, $ctx);
        $assignee  = UserRef::resolve($t->source, $t->assigneeSrcId, $ctx);
        $group     = UserRef::group($t->source, $t->groupSrcId);
        $category  = UserRef::category($t->source, $t->categoryPath);

        $input = [
            'name'                => Mapping::ticketTitle($prefix, $t->displayId ?? $t->srcId, $t->subject),
            'content'             => $t->content !== '' ? $t->content : '(no description)',
            'entities_id'         => $ctx->targetEntity(),
            'type'                => $type['value'],
            'urgency'             => $priority['value'],
            'impact'              => $priority['value'],
            'priority'            => $priority['value'],
            'itilcategories_id'   => $category,
            'date'                => Mapping::localDate($t->createdAt) ?? date('Y-m-d H:i:s'),
            '_disablenotif'       => true,
        ];
        $ticketId = (int) (new \Ticket())->add($input);
        if ($ticketId <= 0) {
            throw new \RuntimeException('GLPI refused to create the ticket');
        }

        // Actors are added explicitly: Ticket::add silently drops _users_id_* / _groups_id_* when the
        // session's active entity is not the ticket's entity, which is always the case for an import
        // (found on staging 2). Ticket_User / Group_Ticket add() do not depend on the session entity.
        $this->actors($ticketId, $requester, $assignee, $group);

        // Ticket business rules can move a ticket to another entity. Isolation wins (spec §0 principle 5).
        global $DB;
        $row = $DB->request(['SELECT' => ['entities_id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => $ticketId]])->current();
        if ((int) ($row['entities_id'] ?? -1) !== $ctx->targetEntity()) {
            $ctx->log(sprintf('ticket %s: a business rule moved GLPI ticket %d to entity %d; put back in %d', $t->srcId, $ticketId, (int) $row['entities_id'], $ctx->targetEntity()));
            $DB->update('glpi_tickets', ['entities_id' => $ctx->targetEntity()], ['id' => $ticketId]);
        }
        return $ticketId;
    }

    /** Requester, assignee and assigned group, each only when missing. A 0 means "nobody" and adds nothing. */
    private function actors(int $ticketId, int $requester, int $assignee, int $group): void
    {
        foreach ([[1, $requester], [2, $assignee]] as [$type, $uid]) {
            if ($uid > 0 && countElementsInTable('glpi_tickets_users', ['tickets_id' => $ticketId, 'type' => $type, 'users_id' => $uid]) === 0) {
                (new \Ticket_User())->add(['tickets_id' => $ticketId, 'users_id' => $uid, 'type' => $type, '_disablenotif' => true]);
            }
        }
        if ($group > 0 && countElementsInTable('glpi_groups_tickets', ['tickets_id' => $ticketId, 'type' => 2, 'groups_id' => $group]) === 0) {
            (new \Group_Ticket())->add(['tickets_id' => $ticketId, 'groups_id' => $group, 'type' => 2, '_disablenotif' => true]);
        }
    }

    /** @return int GLPI ticket id of a ticket another source created for the same real ticket, else 0 */
    private function findLinkTarget(ExternalTicket $t): int
    {
        $origin = $t->originRef ?? ($t->source . ':ticket:' . $t->srcId);
        foreach (MapRepository::findByOrigin($origin) as $row) {
            if ((string) $row['itemtype'] === 'Ticket' && (int) $row['items_id'] > 0 && $row['source'] !== $t->source) {
                return (int) $row['items_id'];
            }
        }
        return 0;
    }

    private function findByTitlePrefix(string $prefix, string $displayId, int $entity): int
    {
        global $DB;
        $like = sprintf('[%s#%s] %%', $prefix, $displayId);
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity, 'is_deleted' => 0, 'name' => ['LIKE', $like]], 'LIMIT' => 1]) as $row) {
            return (int) $row['id'];
        }
        return 0;
    }

    // ------------------------------------------------------------ children

    /** @return list<string> errors; an empty list means every child is in place */
    private function children(ExternalTicket $t, TicketChildren $c, int $ticketId, Context $ctx): array
    {
        $errors = [];
        $run = function (string $what, string $id, callable $fn) use (&$errors, $ctx): void {
            try {
                $fn();
            } catch (\Throwable $e) {
                $errors[] = "$what $id: " . $e->getMessage();
                $ctx->log("  $what $id FAILED: " . $e->getMessage());
            }
        };
        foreach ($c->notes as $n) {
            $run('note', $n->srcId, fn () => $this->note($n, $ticketId, $ctx));
        }
        foreach ($c->tasks as $k) {
            $run('task', $k->srcId, fn () => $this->task($k, $ticketId, $ctx));
        }
        foreach ($c->worklogs as $w) {
            $run('worklog', $w->srcId, fn () => $this->worklog($w, $ticketId, $ctx));
        }
        if ($c->resolution !== null) {
            $run('solution', $t->srcId, fn () => $this->solution($t, $c, $ticketId, $ctx));
        }
        foreach ($c->attachments as $a) {
            $run('attachment', $a->srcId, fn () => $this->attachment($a, $t, $ticketId, $ctx, $this->adapterRef));
        }
        return $errors;
    }

    /**
     * One child: skip when mapped ok, adopt by natural key, else add; always map right after the add.
     *
     * @param callable():int $find   natural-key lookup, returns the GLPI id or 0
     * @param callable():int $create adds the child, returns the GLPI id
     */
    private function child(string $source, string $srcType, string $srcId, string $itemtype, ?string $hash, Context $ctx, callable $find, callable $create, \GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalItem $item): void
    {
        $row = MapRepository::find($source, $srcType, $srcId);
        if ($row !== null && in_array($row['status'], [MapRepository::OK, MapRepository::SKIPPED], true)) {
            return;
        }
        $id = 0;
        $action = MapRepository::CREATED;
        if ($row !== null && (int) $row['items_id'] > 0) {
            $id = (int) $row['items_id']; // resume
            $action = (string) $row['action'];
        } else {
            $id = $find();
            if ($id > 0) {
                $action = MapRepository::MATCHED;
                $ctx->log(sprintf('  %s %s adopted by natural key (GLPI %d)', $srcType, $srcId, $id));
            }
        }
        if ($id === 0) {
            $id = $create();
        }
        $rowId = Recorder::open($ctx, $item, $row, $itemtype, $id, $action, ['content_hash' => $hash]);
        Recorder::ok($ctx, $rowId);
    }

    private function note(ExternalNote $n, int $ticketId, Context $ctx): void
    {
        $hash = Mapping::contentHash($n->body);
        $date = Mapping::localDate($n->createdAt);
        $this->child($n->source, 'note', $n->srcId, 'ITILFollowup', $hash, $ctx,
            fn () => $this->findFollowup($ticketId, $date, $hash),
            function () use ($n, $ticketId, $date): int {
                $id = (int) (new \ITILFollowup())->add([
                    'itemtype'      => 'Ticket',
                    'items_id'      => $ticketId,
                    'content'       => $n->body !== '' ? $n->body : '(empty)',
                    'is_private'    => $n->isPrivate ? 1 : 0,
                    'users_id'      => 0,
                    'date'          => $date,
                    '_disablenotif' => true,
                ]);
                if ($id <= 0) {
                    throw new \RuntimeException('GLPI refused the followup');
                }
                return $id;
            }, $n);
        $this->setAuthor('glpi_itilfollowups', $this->mappedId('note', $n), $n->source, $n->authorSrcId, $ctx);
    }

    private function task(ExternalTask $k, int $ticketId, Context $ctx): void
    {
        $body = '<p><b>' . htmlspecialchars($k->title, ENT_QUOTES) . '</b></p>' . $k->body;
        $hash = Mapping::contentHash($body);
        $date = Mapping::localDate($k->createdAt);
        $this->child($k->source, 'task', $k->srcId, 'TicketTask', $hash, $ctx,
            fn () => $this->findTask($ticketId, $date, $hash),
            function () use ($k, $ticketId, $body, $date, $ctx): int {
                $id = (int) (new \TicketTask())->add([
                    'tickets_id'    => $ticketId,
                    'content'       => $body,
                    'state'         => Mapping::taskState($k->state),
                    'users_id_tech' => UserRef::resolve($k->source, $k->assigneeSrcId, $ctx),
                    'date'          => $date,
                    'is_private'    => 0,
                    '_disablenotif' => true,
                ]);
                if ($id <= 0) {
                    throw new \RuntimeException('GLPI refused the task');
                }
                return $id;
            }, $k);
    }

    private function worklog(ExternalWorklog $w, int $ticketId, Context $ctx): void
    {
        $body = $w->note !== '' ? $w->note : 'Time entry';
        $hash = Mapping::contentHash($body . '|' . $w->seconds);
        $date = Mapping::localDate($w->executedAt);
        $this->child($w->source, 'worklog', $w->srcId, 'TicketTask', $hash, $ctx,
            fn () => $this->findTask($ticketId, $date, Mapping::contentHash($body)),
            function () use ($w, $ticketId, $body, $date, $ctx): int {
                $id = (int) (new \TicketTask())->add([
                    'tickets_id'    => $ticketId,
                    'content'       => $body,
                    'state'         => 2,
                    'actiontime'    => $w->seconds,
                    'users_id_tech' => UserRef::resolve($w->source, $w->authorSrcId, $ctx),
                    'date'          => $date,
                    'is_private'    => 0,
                    '_disablenotif' => true,
                ]);
                if ($id <= 0) {
                    throw new \RuntimeException('GLPI refused the worklog');
                }
                return $id;
            }, $w);
    }

    private function solution(ExternalTicket $t, TicketChildren $c, int $ticketId, Context $ctx): void
    {
        $item = new class ($t->source, 'solution', $t->srcId) extends \GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalItem {
        };
        $hash = Mapping::contentHash((string) $c->resolution);
        $this->child($t->source, 'solution', $t->srcId, 'ITILSolution', $hash, $ctx,
            fn () => countElementsInTable('glpi_itilsolutions', ['itemtype' => 'Ticket', 'items_id' => $ticketId]) > 0 ? $this->firstSolution($ticketId) : 0,
            function () use ($t, $c, $ticketId, $ctx): int {
                $id = (int) (new \ITILSolution())->add([
                    'itemtype'      => 'Ticket',
                    'items_id'      => $ticketId,
                    'content'       => (string) $c->resolution,
                    'status'        => $t->status === 'Closed' ? 3 : 2,
                    'users_id'      => UserRef::resolve($t->source, $t->assigneeSrcId, $ctx),
                    'date_creation' => Mapping::localDate($c->resolvedAt) ?? date('Y-m-d H:i:s'),
                    '_disablenotif' => true,
                ]);
                if ($id <= 0) {
                    throw new \RuntimeException('GLPI refused the solution');
                }
                return $id;
            }, $item);
    }

    private function attachment(ExternalAttachment $a, ExternalTicket $t, int $ticketId, Context $ctx, ?SourceAdapterInterface $adapter): void
    {
        if ($adapter === null) {
            throw new \LogicException('no adapter to download with');
        }
        $row = MapRepository::find($a->source, 'document', $a->srcId);
        if ($row !== null && in_array($row['status'], [MapRepository::OK, MapRepository::SKIPPED], true)) {
            return;
        }

        // Download right away: Freshservice links are short-lived.
        $dir  = defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir();
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $a->name) ?: 'attachment';
        $tmpName = 'itsm_' . bin2hex(random_bytes(6)) . '_' . $safe;
        $path = rtrim((string) $dir, '/\\') . DIRECTORY_SEPARATOR . $tmpName;
        $adapter->downloadAttachment($a, $path);
        try {
            $sha = sha1_file($path);
            if ($sha === false) {
                throw new \RuntimeException('downloaded file unreadable');
            }
            global $DB;
            // Natural key: the same file already in the target entity is reused, only the link is added.
            $docId = 0;
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_documents', 'WHERE' => ['sha1sum' => $sha, 'entities_id' => $ctx->targetEntity(), 'is_deleted' => 0], 'LIMIT' => 1]) as $d) {
                $docId = (int) $d['id'];
            }
            $action = $docId > 0 ? MapRepository::MATCHED : MapRepository::CREATED;
            if ($docId === 0) {
                $docId = (int) (new \Document())->add([
                    'name'               => $a->name,
                    'entities_id'        => $ctx->targetEntity(),
                    'is_recursive'       => 0,
                    '_filename'          => [$tmpName],
                    '_only_if_upload_succeed' => true,
                ]);
                if ($docId <= 0) {
                    throw new \RuntimeException('GLPI refused the document');
                }
            }
            $rowId = Recorder::open($ctx, $a, $row, 'Document', $docId, $action, ['content_hash' => $sha]);
            if (countElementsInTable('glpi_documents_items', ['documents_id' => $docId, 'itemtype' => 'Ticket', 'items_id' => $ticketId]) === 0) {
                (new \Document_Item())->add(['documents_id' => $docId, 'itemtype' => 'Ticket', 'items_id' => $ticketId, 'entities_id' => $ctx->targetEntity(), 'is_recursive' => 0]);
            }
            Recorder::ok($ctx, $rowId);
        } finally {
            if (is_file($path)) {
                @unlink($path); // Document::add moves the file; this only cleans up a failure
            }
        }
    }

    // ------------------------------------------------------------ finalize

    /** Status, dates and due time straight into the table so GLPI does not recalculate them (spec §8.3). */
    private function finalize(ExternalTicket $t, TicketChildren $c, int $ticketId, Context $ctx): void
    {
        global $DB;
        $status = Mapping::status($t->status, $t->assigneeSrcId !== null, $this->jsonOpt($ctx, 'status_map'))['value'];
        $set = [
            'status'   => $status,
            'date'     => Mapping::localDate($t->createdAt) ?? date('Y-m-d H:i:s'),
            'date_mod' => Mapping::localDate($t->updatedAt) ?? date('Y-m-d H:i:s'),
        ];
        $solved = Mapping::localDate($c->resolvedAt);
        $closed = Mapping::localDate($c->closedAt);
        if ($status >= Mapping::SOLVED) {
            // A solved or closed ticket without a date from the source falls back to its last update.
            $set['solvedate'] = $solved ?? $set['date_mod'];
        }
        if ($status === Mapping::CLOSED) {
            $set['closedate'] = $closed ?? $solved ?? $set['date_mod'];
        }
        $due = Mapping::localDate($t->dueAt);
        if ($due !== null) {
            $set['time_to_resolve'] = $due;
        }
        $DB->update('glpi_tickets', $set, ['id' => $ticketId]);
    }

    // ------------------------------------------------------------- lookups

    private function findFollowup(int $ticketId, ?string $date, string $hash): int
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['id', 'content'], 'FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $ticketId, 'date' => $date]]) as $r) {
            if (Mapping::contentHash((string) $r['content']) === $hash) {
                return (int) $r['id'];
            }
        }
        return 0;
    }

    private function findTask(int $ticketId, ?string $date, string $hash): int
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['id', 'content'], 'FROM' => 'glpi_tickettasks', 'WHERE' => ['tickets_id' => $ticketId, 'date' => $date]]) as $r) {
            if (Mapping::contentHash((string) $r['content']) === $hash) {
                return (int) $r['id'];
            }
        }
        return 0;
    }

    private function firstSolution(int $ticketId): int
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_itilsolutions', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $ticketId], 'LIMIT' => 1]) as $r) {
            return (int) $r['id'];
        }
        return 0;
    }

    private function mappedId(string $type, \GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalItem $i): int
    {
        $row = MapRepository::find($i->source, $type, $i->srcId);
        return (int) ($row['items_id'] ?? 0);
    }

    /** The followup author, set straight in the table: ITILFollowup::add forces the session user. */
    private function setAuthor(string $table, int $id, string $source, ?string $authorSrcId, Context $ctx): void
    {
        global $DB;
        if ($id <= 0 || $ctx->dryRun()) {
            return;
        }
        $uid = UserRef::resolve($source, $authorSrcId, $ctx);
        if ($uid > 0) {
            $DB->update($table, ['users_id' => $uid], ['id' => $id]);
        }
    }

    /** @return array<string,mixed> the source's JSON map (status_map, priority_map, type_map) */
    private function jsonOpt(Context $ctx, string $key): array
    {
        $v = json_decode((string) ($ctx->source[$key] ?? ''), true);
        return is_array($v) ? $v : [];
    }
}
