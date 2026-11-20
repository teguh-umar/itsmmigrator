<?php

namespace GlpiPlugin\Itsmmigrator\Writer;

use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalApproval;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalAttachment;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalNote;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalTask;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalTicket;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalWorklog;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\TicketChildren;
use GlpiPlugin\Itsmmigrator\Adapter\ClientException;
use GlpiPlugin\Itsmmigrator\Adapter\SourceAdapterInterface;
use GlpiPlugin\Itsmmigrator\AttachmentRule;
use GlpiPlugin\Itsmmigrator\CategoryFilter;
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
        // Early-phase scope: only the chosen categories. A ticket left out gets NO map row, so widening the filter later picks it up.
        if (!CategoryFilter::allowsTicket($ctx->categoryFilter(), $t->categoryPath)) {
            $ctx->counters->add($mod, 'skipped');
            return 'skipped';
        }

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
                $this->warnUnmapped($t, $ctx);
                $ctx->log(sprintf('[dry] ticket %s: would %s', $t->srcId, $ticketId > 0 ? 'reuse GLPI ticket ' . $ticketId : 'create'));
                return $ticketId > 0 ? 'matched' : 'created';
            }

            if ($ticketId === 0) {
                $ticketId = $this->add($t, $prefix, $ctx);
            }
            // 4. Mapped right after add(): a crash from here on resumes instead of re-adding.
            $rowId = Recorder::open($ctx, $t, $existing, 'Ticket', $ticketId, $action);

            // 5. Children, each mapped on its own.
            $children = $adapter->fetchTicketChildren($t->srcId, self::wantsApprovals($ctx));
            $errors   = $this->children($t, $children, $ticketId, $ctx);

            // 5b. The asset a monitor ticket is about (an asset that exists in GLPI; otherwise a private note).
            $this->linkAsset($ticketId, $ctx);

            // 6. Status and dates last.
            $this->finalize($t, $children, $ticketId, $ctx);

            // 7.
            if ($errors !== []) {
                Recorder::failed($ctx, $rowId, implode(' | ', array_slice($errors, 0, 5)));
                $ctx->counters->add($mod, 'failed');
                return 'failed';
            }
            Recorder::ok($ctx, $rowId);
            $ctx->authOk();
            $ctx->counters->add($mod, $action === MapRepository::CREATED ? 'created' : 'matched');
            return $action === MapRepository::CREATED ? 'created' : 'matched';
        } catch (\Throwable $e) {
            Recorder::fail($ctx, $t, $existing, $rowId, $e->getMessage());
            $ctx->counters->add($mod, 'failed');
            $ctx->log(sprintf('ticket %s FAILED: %s', $t->srcId, $e->getMessage()));
            if ($e instanceof ClientException && in_array($e->status, [401, 403], true) && $ctx->authFailed()) {
                // The key itself is refused, not these tickets: stop here instead of failing every ticket that follows.
                throw new \RuntimeException(sprintf('The source refused the API key on %d tickets in a row (HTTP %d): the key is blocked, revoked or has lost its role. Nothing is wrong with the tickets. Check the key, then run again with retry-failed.', Context::AUTH_STREAK, $e->status));
            }
            return 'failed';
        }
    }

    /** A failure here never fails the ticket. */
    private function linkAsset(int $ticketId, Context $ctx): void
    {
        try {
            $r = \GlpiPlugin\Itsmmigrator\AssetLinker::apply($ticketId, $ctx->dryRun());
        } catch (\Throwable $e) {
            $ctx->log(sprintf('  asset link for ticket %d failed: %s', $ticketId, $e->getMessage()));
            return;
        }
        if ($r !== 'none') {
            $ctx->counters->detail('assets', $r);
        }
    }

    /** @var array<string,true> labels already reported in this run */
    private array $warned = [];

    /**
     * Report a status, priority or type label that neither the source's maps nor the defaults know, once per
     * label per run (a dry run is where an administrator needs to see them, before anything is written).
     */
    private function warnUnmapped(ExternalTicket $t, Context $ctx): void
    {
        $checks = [
            ['status', $t->status, Mapping::status($t->status, $t->assigneeSrcId !== null, $this->jsonOpt($ctx, 'status_map'))],
            ['priority', $t->priority, Mapping::priority($t->priority, $this->jsonOpt($ctx, 'priority_map'))],
            ['type', $t->type, Mapping::type($t->type, $this->jsonOpt($ctx, 'type_map'))],
        ];
        foreach ($checks as [$what, $label, $m]) {
            $key = $what . '|' . $label;
            if (!$m['known'] && !isset($this->warned[$key])) {
                $this->warned[$key] = true;
                $ctx->log(sprintf('UNMAPPED %s "%s": a best guess is used (GLPI value %d); set it in the source\'s %s_map', $what, $label, $m['value'], $what));
            }
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

        $content = $t->content !== '' ? $t->content : '(no description)';
        if ($t->requesterSrcId !== null && $t->requesterSrcId !== '' && UserRef::resolve($t->source, $t->requesterSrcId, $ctx, false) === 0) {
            $content = self::requesterLine($this->adapterRef?->describeUser($t->requesterSrcId)) . $content;
        }
        $input = [
            'name'                => Mapping::ticketTitle($prefix, $t->displayId ?? $t->srcId, $t->subject),
            'content'             => $content,
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

    /**
     * The first line of a ticket whose requester is not a GLPI user: who asked, as the source knows them, so the
     * identity is never lost (a later run skips mapped tickets and could not add it). Pure.
     *
     * @param array{name:string,email:string}|null $who
     */
    public static function requesterLine(?array $who): string
    {
        $label = $who === null ? '' : trim($who['name'] . ($who['email'] !== '' ? ' <' . $who['email'] . '>' : ''));
        return '<p><b>' . ($label !== '' ? 'Requester (source): ' . htmlspecialchars($label, ENT_QUOTES) : 'Requester (source): unknown, not readable') . '</b></p>';
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
        foreach ($c->approvals as $ap) {
            $run('approval', $ap->srcId, fn () => $this->approval($ap, $ticketId, $ctx));
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

    /** Approvals are opt-in (source option import_approvals, or the run flag approvals): one more request per ticket. */
    public static function wantsApprovals(Context $ctx): bool
    {
        return !empty($ctx->flag('approvals')) || !empty($ctx->option('import_approvals'));
    }

    /** GLPI approval status of a source status. */
    public static function validationStatus(string $status): int
    {
        return match ($status) {
            'approved' => \CommonITILValidation::ACCEPTED,
            'rejected' => \CommonITILValidation::REFUSED,
            default    => \CommonITILValidation::WAITING,
        };
    }

    /**
     * One approval -> a TicketValidation in the approval step "Level N" (one step per level, so a multi-level chain keeps
     * its order). The approver must be a GLPI user already in the map: an approval whose approver is unknown is logged and
     * counted, never given to the fallback user (that would put a wrong name on a decision).
     */
    private function approval(ExternalApproval $ap, int $ticketId, Context $ctx): void
    {
        global $DB;
        $approver = UserRef::resolve($ap->source, $ap->approverSrcId, $ctx, false);
        if ($approver <= 0) {
            $this->noteUnmappedApproval($ap, $ticketId, $ctx);
            return;
        }
        $submitted = Mapping::localDate($ap->createdAt);
        $this->child($ap->source, 'approval', $ap->srcId, 'TicketValidation', Mapping::contentHash($ap->status . '|' . $approver . '|' . $ap->level), $ctx,
            function () use ($DB, $ticketId, $approver, $submitted): int {
                foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_ticketvalidations', 'WHERE' => ['tickets_id' => $ticketId, 'itemtype_target' => 'User', 'items_id_target' => $approver, 'submission_date' => $submitted], 'LIMIT' => 1]) as $r) {
                    return (int) $r['id'];
                }
                return 0;
            },
            function () use ($ap, $ticketId, $approver, $ctx, $submitted): int {
                $id = (int) (new \TicketValidation())->add([
                    'tickets_id'          => $ticketId,
                    'itemtype_target'     => 'User',
                    'items_id_target'     => $approver,
                    '_validationsteps_id' => $this->levelStep($ap->level),
                    'comment_submission'  => '(imported approval request)',
                    '_auto_import'        => true,
                    '_disablenotif'       => true,
                ]);
                if ($id <= 0) {
                    throw new \RuntimeException('GLPI refused the approval');
                }
                return $id;
            }, $ap);
        // add() forces the session user, "now" and WAITING: the real requester, date and decision go in straight.
        $id = $this->mappedId('approval', $ap);
        if ($id > 0 && !$ctx->dryRun()) {
            $set = ['status' => self::validationStatus($ap->status), 'users_id' => UserRef::resolve($ap->source, $ap->requesterSrcId, $ctx, false)];
            if ($submitted !== null) {
                $set['submission_date'] = $submitted;
            }
            if ($ap->status !== 'requested') {
                $set['validation_date']   = Mapping::localDate($ap->decidedAt);
                $set['comment_validation'] = $ap->remark;
            }
            $DB->update('glpi_ticketvalidations', $set, ['id' => $id]);
            $ctx->counters->detail('approvals', $ap->status);
        }
    }

    /**
     * The text of the private note that stands in for an approval whose approver is not a GLPI user: who, which level,
     * what was decided, when, and the remark, so the decision is never lost. Pure.
     *
     * @param array{name:string,email:string}|null $approver
     */
    public static function approvalNote(?array $approver, int $level, string $status, ?string $decidedAt, ?string $remark): string
    {
        $who   = $approver === null ? '' : trim($approver['name'] . ($approver['email'] !== '' ? ' <' . $approver['email'] . '>' : ''));
        $label = ['approved' => 'Approved', 'rejected' => 'Rejected'][$status] ?? 'Waiting for the answer';
        $out   = '<p><b>Approval (source), level ' . $level . ': ' . $label . '</b> by '
            . htmlspecialchars($who !== '' ? $who : 'an approver who is not readable in the source', ENT_QUOTES)
            . ' (not a GLPI user, so it could not be an approval here).';
        if ($decidedAt !== null && $decidedAt !== '' && $status !== 'requested') {
            $out .= ' Decided: ' . htmlspecialchars($decidedAt, ENT_QUOTES) . '.';
        }
        if ($remark !== null && trim($remark) !== '' && $status !== 'requested') {
            $out .= ' Remark: ' . htmlspecialchars(trim($remark), ENT_QUOTES);
        }
        return $out . '</p>';
    }

    /**
     * An approval whose approver is not a GLPI user (not in the directory): a private followup says who decided what,
     * and the approval is mapped as skipped so a later run does not add the note twice. Never given to a fallback user.
     */
    private function noteUnmappedApproval(ExternalApproval $ap, int $ticketId, Context $ctx): void
    {
        $ctx->counters->detail('approvals', 'skipped_approver_unmapped');
        $row = MapRepository::find($ap->source, 'approval', $ap->srcId);
        if ($row !== null && in_array($row['status'], [MapRepository::OK, MapRepository::SKIPPED], true)) {
            return;
        }
        if ($ctx->dryRun()) {
            $ctx->log(sprintf('  approval %s: the approver is not a GLPI user (level %d); a private followup would say so', $ap->srcId, $ap->level));
            return;
        }
        $note = self::approvalNote($this->adapterRef?->describeUser($ap->approverSrcId), $ap->level, $ap->status, Mapping::localDate($ap->decidedAt), $ap->remark);
        $id   = (int) (new \ITILFollowup())->add([
            'itemtype' => 'Ticket', 'items_id' => $ticketId, 'content' => $note, 'is_private' => 1, 'users_id' => 0,
            'date' => Mapping::localDate($ap->createdAt) ?? date('Y-m-d H:i:s'), '_disablenotif' => true,
        ]);
        if ($id > 0) {
            $rowId = Recorder::open($ctx, $ap, $row, 'Ticket', $ticketId, MapRepository::CREATED);
            if ($rowId > 0) {
                MapRepository::markSkipped($rowId, 'approver is not a GLPI user (noted in a private followup)');
            }
        }
        $ctx->log(sprintf('  approval %s: the approver is not a GLPI user (level %d); a private followup %s', $ap->srcId, $ap->level, $id > 0 ? 'says so' : 'could not be added'));
    }

    /** The core approval step "Level N" (created once, named after the level). */
    private function levelStep(int $level): int
    {
        global $DB;
        $name = 'Level ' . $level;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_validationsteps', 'WHERE' => ['name' => $name], 'LIMIT' => 1]) as $r) {
            return (int) $r['id'];
        }
        $id = (int) (new \ValidationStep())->add(['name' => $name, 'minimal_required_validation_percent' => 100]);
        if ($id <= 0) {
            throw new \RuntimeException('GLPI refused the approval step ' . $name);
        }
        return $id;
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
                $why = AttachmentRule::refusal($a->name, (int) filesize($path), (int) ($GLOBALS['CFG_GLPI']['document_max_size'] ?? 0), $this->uploadableExtensions());
                if ($why !== null) {
                    $this->skipAttachment($a, $t, $ticketId, $why, $row, $ctx);
                    return;
                }
                $docId = (int) (new \Document())->add([
                    'name'               => $a->name,
                    'entities_id'        => $ctx->targetEntity(),
                    'is_recursive'       => 0,
                    '_filename'          => [$tmpName],
                    '_only_if_upload_succeed' => true,
                ]);
                if ($docId <= 0) {
                    $this->skipAttachment($a, $t, $ticketId, 'GLPI refused the document', $row, $ctx);
                    return;
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

    /** @var list<string>|null */
    private ?array $uploadable = null;

    /** @return list<string> */
    private function uploadableExtensions(): array
    {
        global $DB;
        if ($this->uploadable === null) {
            $this->uploadable = [];
            foreach ($DB->request(['SELECT' => ['ext'], 'FROM' => 'glpi_documenttypes', 'WHERE' => ['is_uploadable' => 1]]) as $r) {
                $this->uploadable[] = strtolower((string) $r['ext']);
            }
        }
        return $this->uploadable;
    }

    /**
     * A file GLPI will not take: the ticket is not failed for it. A private followup says which file, how big and why, so
     * it can still be fetched from the source; the attachment is mapped as skipped (counted, not retried every run).
     *
     * @param array<string,mixed>|null $row
     */
    private function skipAttachment(ExternalAttachment $a, ExternalTicket $t, int $ticketId, string $why, ?array $row, Context $ctx): void
    {
        $note = '<p><b>Attachment not imported:</b> ' . htmlspecialchars($a->name, ENT_QUOTES) . ' (' . AttachmentRule::size($a->size) . '), ' . htmlspecialchars($why, ENT_QUOTES) . '. It is still in the source ticket.</p>';
        $id = (int) (new \ITILFollowup())->add([
            'itemtype' => 'Ticket', 'items_id' => $ticketId, 'content' => $note, 'is_private' => 1, 'users_id' => 0,
            'date' => date('Y-m-d H:i:s'), '_disablenotif' => true,
        ]);
        $rowId = Recorder::open($ctx, $a, $row, 'Ticket', $ticketId, MapRepository::CREATED);
        if ($rowId > 0) {
            MapRepository::markSkipped($rowId, $why);
        }
        $ctx->counters->detail('attachments', 'skipped_refused_by_glpi');
        $ctx->log(sprintf('  attachment %s not imported (%s); a private followup %s', $a->srcId, $why, $id > 0 ? 'says so' : 'could not be added'));
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
