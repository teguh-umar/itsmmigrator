<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Freshservice;

use GlpiPlugin\Itsmmigrator\Adapter\ClientException;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalAttachment;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalCategory;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalGroup;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalNote;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalTask;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalTicket;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalUser;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalWorklog;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\Page;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalApproval;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\TicketChildren;
use GlpiPlugin\Itsmmigrator\Adapter\SourceAdapterInterface;

/**
 * Freshservice API v2 -> normalized DTOs. WRITTEN FROM THE v2 DOCUMENTATION, NOT YET CHECKED AGAINST
 * A REAL ACCOUNT: field names, the category choices shape, the resolution and `stats` fields are the
 * parts most likely to need a fix once real responses are recorded (CHANGELOG, open items).
 *
 * User ids: agents and requesters are separate objects, so their source ids are prefixed
 * `agent:<id>` and `requester:<id>`. A bare id on a note author is resolved by UserRef.
 */
final class FreshserviceAdapter implements SourceAdapterInterface
{
    public const SOURCE = 'freshservice';

    /** Freshservice stops paging at 300 pages of 100: a bigger history needs --since windows. */
    private const MAX_PAGE = 300;

    private const STATUS   = [2 => 'Open', 3 => 'Pending', 4 => 'Resolved', 5 => 'Closed'];
    private const PRIORITY = [1 => 'Low', 2 => 'Medium', 3 => 'High', 4 => 'Urgent'];
    private const TASK     = [1 => 'todo', 2 => 'in_progress', 3 => 'done'];

    /** @var array{status: array<int,string>, priority: array<int,string>}|null status and priority wording of THIS account */
    private ?array $labels = null;

    /**
     * @param int $maxPage the last page Freshservice serves for one list query (300 x 100 = 30,000 tickets); a test lowers it
     */
    public function __construct(private readonly FreshserviceClient $client, private readonly int $maxPage = self::MAX_PAGE)
    {
    }

    /**
     * The account's own wording for status and priority codes, read once from the ticket form
     * (`status` choices: id 2 => "Open", 6 => "In Progress", 7 => "Waiting on Approval" ...). A code the
     * account added is therefore reported by its real label and mapped by the source's status_map, instead
     * of being silently treated as "Open". Falls back to the built-in names when the form cannot be read.
     *
     * @return array{status: array<int,string>, priority: array<int,string>}
     */
    private function labels(): array
    {
        if ($this->labels !== null) {
            return $this->labels;
        }
        $this->labels = ['status' => self::STATUS, 'priority' => self::PRIORITY];
        try {
            $fields = $this->client->get('/ticket_form_fields')['json']['ticket_fields'] ?? [];
        } catch (ClientException) {
            return $this->labels;
        }
        foreach ($fields as $f) {
            $name = (string) ($f['name'] ?? '');
            if ($name === 'status' || $name === 'priority') {
                $this->labels[$name] = self::choiceLabels((array) ($f['choices'] ?? [])) + $this->labels[$name];
            }
        }
        return $this->labels;
    }

    /**
     * Pure: {id: 2, value: "Open"} choices -> [2 => "Open"].
     *
     * @param array<mixed> $choices
     * @return array<int,string>
     */
    public static function choiceLabels(array $choices): array
    {
        $out = [];
        foreach ($choices as $ch) {
            if (is_array($ch) && isset($ch['id'], $ch['value']) && is_numeric($ch['id']) && is_string($ch['value']) && trim($ch['value']) !== '') {
                $out[(int) $ch['id']] = trim($ch['value']);
            }
        }
        return $out;
    }

    public function getSourceKey(): string
    {
        return self::SOURCE;
    }

    public function supports(string $module): bool
    {
        return in_array($module, ['users', 'groups', 'categories', 'tickets'], true);
    }

    public function testConnection(): array
    {
        try {
            $r = $this->client->get('/agents', ['per_page' => 1]);
            return ['ok' => isset($r['json']['agents']), 'message' => isset($r['json']['agents']) ? 'Connected to Freshservice' : 'Unexpected answer from /agents'];
        } catch (ClientException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function fetchPage(string $module, int $page, ?\DateTimeImmutable $since, ?string $cursor = null): Page
    {
        return match ($module) {
            'users'      => $this->usersPage($cursor),
            'groups'     => $this->groupsPage($page),
            'categories' => $this->categoriesPage($page),
            'tickets'    => $this->ticketsPage($page, $since, $cursor),
            default      => throw new \InvalidArgumentException("Module $module is not supported by Freshservice"),
        };
    }

    // ---------------------------------------------------------------- users

    /**
     * users = agents first, then requesters. The position is the cursor "<phase>:<page>" so the next
     * page can be fetched by another process. An unreadable cursor starts over at the first agents page.
     */
    private function usersPage(?string $cursor): Page
    {
        $phase = 'agents';
        $page  = 1;
        if ($cursor !== null && preg_match('/^(agents|requesters):(\d{1,4})$/', $cursor, $m)) {
            $phase = $m[1];
            $page  = (int) $m[2];
        }
        $isAgent = $phase === 'agents';
        $r       = $this->client->get($isAgent ? '/agents' : '/requesters', ['per_page' => FreshserviceClient::PER_PAGE, 'page' => $page]);
        $rows    = $r['json'][$isAgent ? 'agents' : 'requesters'] ?? [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = $isAgent ? self::agent($row) : self::requester($row);
        }

        if (self::more($r, count($rows))) {
            return new Page($items, true, $phase . ':' . ($page + 1));
        }
        if ($isAgent) {
            return new Page($items, true, 'requesters:1'); // requesters follow
        }
        return new Page($items, false);
    }
    /** @var array<string,array{name:string,email:string}|null> */
    private array $described = [];

    public function describeUser(string $userSrcId): ?array
    {
        if (array_key_exists($userSrcId, $this->described)) {
            return $this->described[$userSrcId];
        }
        $isAgent = str_starts_with($userSrcId, 'agent:');
        $id      = preg_replace('/^(agent|requester):/', '', $userSrcId);
        $out     = null;
        if (preg_match('/^\d{1,20}$/', (string) $id)) {
            try {
                $r  = $this->client->get(($isAgent ? '/agents/' : '/requesters/') . $id);
                $u  = $r['json'][$isAgent ? 'agent' : 'requester'] ?? null;
                if (is_array($u) && isset($u['id'])) {
                    $x   = $isAgent ? self::agent($u) : self::requester($u);
                    $out = ['name' => $x->name, 'email' => $x->emails[0] ?? ''];
                }
            } catch (\Throwable) {
                $out = null; // the ticket is still created, without the line
            }
        }
        return $this->described[$userSrcId] = $out;
    }

    /** @param array<string,mixed> $a */
    public static function agent(array $a): ExternalUser
    {
        $id = (string) $a['id'];
        return new ExternalUser(
            self::SOURCE,
            'agent:' . $id,
            self::text(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''))),
            array_values(array_filter([(string) ($a['email'] ?? '')])),
            null,
            true,
            $id,
            self::date($a['updated_at'] ?? null)
        );
    }

    /** @param array<string,mixed> $r */
    public static function requester(array $r): ExternalUser
    {
        $id = (string) $r['id'];
        $emails = array_merge([(string) ($r['primary_email'] ?? '')], array_map('strval', (array) ($r['secondary_emails'] ?? [])));
        return new ExternalUser(
            self::SOURCE,
            'requester:' . $id,
            self::text(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))),
            array_values(array_filter($emails)),
            null,
            false,
            $id,
            self::date($r['updated_at'] ?? null)
        );
    }

    // --------------------------------------------------------------- groups

    private function groupsPage(int $page): Page
    {
        $r    = $this->client->get('/groups', ['per_page' => FreshserviceClient::PER_PAGE, 'page' => $page]);
        $rows = $r['json']['groups'] ?? [];
        $out  = [];
        foreach ($rows as $g) {
            $out[] = new ExternalGroup(self::SOURCE, (string) $g['id'], self::text($g['name'] ?? ''), $this->members($g), self::date($g['updated_at'] ?? null));
        }
        return new Page($out, self::more($r, count($rows)));
    }

    /**
     * The group's members as `agent:<id>` references (only agents are group members in Freshservice).
     * The list answer is used when it carries them; otherwise the group is fetched once. UNVERIFIED:
     * the key is `agent_ids` in the v2 docs this was written from, `members` is accepted as well.
     *
     * @param array<string,mixed> $g a row of /groups
     * @return list<string>
     */
    private function members(array $g): array
    {
        if (!array_key_exists('agent_ids', $g) && !array_key_exists('members', $g)) {
            $g = $this->client->get('/groups/' . rawurlencode((string) $g['id']))['json']['group'] ?? $g;
        }
        return self::memberRefs($g);
    }

    /**
     * Pure: member references out of a group object. Accepts a list of ids or a list of objects with an id.
     *
     * @param array<string,mixed> $g
     * @return list<string>
     */
    public static function memberRefs(array $g): array
    {
        $raw = $g['agent_ids'] ?? $g['members'] ?? [];
        $out = [];
        foreach ((array) $raw as $m) {
            $id = is_array($m) ? ($m['id'] ?? null) : $m;
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $ref = 'agent:' . (int) $id;
                if (!in_array($ref, $out, true)) {
                    $out[] = $ref;
                }
            }
        }
        return $out;
    }
    // ----------------------------------------------------------- categories

    private function categoriesPage(int $page): Page
    {
        if ($page > 1) {
            return new Page([], false);
        }
        $r = $this->client->get('/ticket_form_fields');
        $paths = [];
        foreach ($r['json']['ticket_fields'] ?? [] as $field) {
            if (($field['name'] ?? '') === 'category') {
                self::walkChoices((array) ($field['choices'] ?? []), [], $paths);
            }
        }
        $items = [];
        foreach ($paths as $path) {
            $items[] = new ExternalCategory(self::SOURCE, implode(' > ', $path), $path);
        }
        return new Page($items, false);
    }

    /**
     * Tolerant walk over the choices of the category field: a choice is a string, a [label, children]
     * pair, or an object with a label/value. Every node becomes a path. UNVERIFIED shape.
     *
     * @param array<mixed> $choices
     * @param list<string> $prefix
     * @param list<list<string>> $out
     */
    public static function walkChoices(array $choices, array $prefix, array &$out): void
    {
        foreach ($choices as $key => $choice) {
            $label = null;
            $kids  = [];
            if (is_string($choice)) {
                $label = self::text($choice);
            } elseif (is_array($choice) && array_is_list($choice) && isset($choice[0]) && is_string($choice[0])) {
                $label = $choice[0];
                $kids  = is_array($choice[1] ?? null) ? $choice[1] : [];
            } elseif (is_array($choice)) {
                $label = self::text($choice['label'] ?? $choice['value'] ?? '');
                $kids  = (array) ($choice['choices'] ?? $choice['nested_options'] ?? []);
            } elseif (is_string($key) && is_array($choice)) {
                $label = $key;
            }
            if ($label === null || trim($label) === '') {
                continue;
            }
            $path  = array_merge($prefix, [trim($label)]);
            $out[] = $path;
            if ($kids !== []) {
                self::walkChoices($kids, $path, $out);
            }
        }
    }

    // -------------------------------------------------------------- tickets

    /**
     * One page of the ticket list, read in WINDOWS. Freshservice serves at most `maxPage` pages of 100 tickets per
     * query (30,000), oldest update first. When the last servable page is full the list is not over: the next window
     * starts at the update time of the last ticket seen and the cursor says so ("w:<UTC time>:<page>"), so the
     * caller just keeps asking for the next page. The window start is inclusive: a ticket updated in the very second
     * the window starts is read twice, and the second read is a no-op for the writer (it is already mapped).
     *
     * If a whole window shares one update second (more than the cap), skipping ahead one second is the only way
     * forward; that is said in the notes, because tickets of that second beyond the cap cannot be read.
     */
    private function ticketsPage(int $page, ?\DateTimeImmutable $since, ?string $cursor): Page
    {
        [$from, $pg] = self::parseWindow($cursor, $since ?? new \DateTimeImmutable('2010-01-01T00:00:00Z'), $page);
        if ($pg > $this->maxPage) {
            throw new ClientException('Freshservice stops paging after ' . $this->maxPage . ' pages for one query', 0, 'limit');
        }
        // updated_since is mandatory: without it the list only returns the last 30 days.
        $r = $this->client->get('/tickets', [
            'updated_since' => self::iso($from),
            'order_by'      => 'updated_at',
            'order_type'    => 'asc',
            'per_page'      => FreshserviceClient::PER_PAGE,
            'page'          => $pg,
        ]);
        $rows   = $r['json']['tickets'] ?? [];
        $labels = $this->labels();
        $items  = [];
        foreach ($rows as $t) {
            $items[] = self::ticket($t, $labels['status'], $labels['priority']);
        }
        $more = self::more($r, count($rows));
        if (!$more) {
            return new Page($items, false);
        }
        if ($pg < $this->maxPage) {
            return new Page($items, true, 'w:' . self::iso($from) . ':' . ($pg + 1));
        }

        // The cap is reached and the page is full: continue in a new window.
        $notes = [];
        $last  = $items === [] ? null : $items[count($items) - 1]->updatedAt;
        if ($last === null) {
            return new Page($items, false, null, ['The last page of the window has no update time: the list cannot be continued']);
        }
        $next = $last->setTimezone(new \DateTimeZone('UTC'));
        if ($next <= $from) {
            $next    = $from->modify('+1 second');
            $notes[] = sprintf('More than %d tickets share the update time %s: skipping ahead one second, the tickets of that second beyond the cap cannot be read', $this->maxPage * FreshserviceClient::PER_PAGE, self::iso($from));
        }
        $notes[] = 'Window ended at the ' . ($this->maxPage * FreshserviceClient::PER_PAGE) . ' ticket cap: the next window starts at ' . self::iso($next);
        return new Page($items, true, 'w:' . self::iso($next) . ':1', $notes);
    }

    /**
     * "w:<UTC time>:<page>" -> [window start, page]. No cursor = the caller's since and page. An unreadable cursor
     * is refused, never guessed. Pure.
     *
     * @return array{0: \DateTimeImmutable, 1: int}
     */
    public static function parseWindow(?string $cursor, \DateTimeImmutable $since, int $page): array
    {
        if ($cursor === null || $cursor === '') {
            return [$since->setTimezone(new \DateTimeZone('UTC')), $page];
        }
        if (!preg_match('/^w:(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z):(\d{1,4})$/', $cursor, $m)) {
            throw new \InvalidArgumentException('Unreadable ticket cursor');
        }
        return [new \DateTimeImmutable($m[1], new \DateTimeZone('UTC')), (int) $m[2]];
    }

    private static function iso(\DateTimeImmutable $d): string
    {
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
    /** @param array<string,mixed> $t */
    public static function ticket(array $t, array $statusLabels = self::STATUS, array $priorityLabels = self::PRIORITY): ExternalTicket
    {
        $path = array_values(array_filter([self::text($t['category'] ?? ''), self::text($t['sub_category'] ?? ''), self::text($t['item_category'] ?? '')], static fn ($s) => $s !== ''));
        return new ExternalTicket(
            self::SOURCE,
            (string) $t['id'],
            self::text($t['subject'] ?? ''),
            (string) ($t['description'] ?? ($t['description_text'] ?? '')),
            $statusLabels[(int) ($t['status'] ?? 0)] ?? self::STATUS[(int) ($t['status'] ?? 0)] ?? 'Open',
            $priorityLabels[(int) ($t['priority'] ?? 0)] ?? self::PRIORITY[(int) ($t['priority'] ?? 0)] ?? 'Medium',
            (string) ($t['type'] ?? 'Incident'),
            isset($t['requester_id']) ? 'requester:' . $t['requester_id'] : null,
            isset($t['responder_id']) ? 'agent:' . $t['responder_id'] : null,
            isset($t['group_id']) ? (string) $t['group_id'] : null,
            $path,
            self::date($t['created_at'] ?? null),
            self::date($t['due_by'] ?? null),
            (string) $t['id'],
            self::date($t['updated_at'] ?? null)
        );
    }

    // ------------------------------------------------------------- children

    public function fetchTicketChildren(string $ticketSrcId, bool $approvals = false): TicketChildren
    {
        $id   = rawurlencode($ticketSrcId);
        $main = $this->client->get("/tickets/$id", ['include' => 'stats'])['json']['ticket'] ?? [];

        $attachments = [];
        foreach ((array) ($main['attachments'] ?? []) as $a) {
            $attachments[] = self::attachment($a, $ticketSrcId, null);
        }

        $notes = [];
        foreach ($this->all("/tickets/$id/conversations", 'conversations') as $c) {
            $nid = (string) $c['id'];
            $notes[] = new ExternalNote(
                self::SOURCE,
                $nid,
                $ticketSrcId,
                (string) ($c['body'] ?? $c['body_text'] ?? ''),
                !empty($c['private']),
                isset($c['user_id']) ? (string) $c['user_id'] : null,
                self::date($c['created_at'] ?? null)
            );
            foreach ((array) ($c['attachments'] ?? []) as $a) {
                $attachments[] = self::attachment($a, $ticketSrcId, $nid);
            }
        }

        $tasks = [];
        foreach ($this->all("/tickets/$id/tasks", 'tasks') as $t) {
            $tasks[] = new ExternalTask(
                self::SOURCE,
                (string) $t['id'],
                $ticketSrcId,
                (string) ($t['title'] ?? ''),
                (string) ($t['description'] ?? ''),
                self::TASK[(int) ($t['status'] ?? 1)] ?? 'todo',
                isset($t['agent_id']) ? 'agent:' . $t['agent_id'] : null,
                self::date($t['created_at'] ?? null)
            );
        }

        $worklogs = [];
        foreach ($this->all("/tickets/$id/time_entries", 'time_entries') as $w) {
            $worklogs[] = new ExternalWorklog(
                self::SOURCE,
                (string) $w['id'],
                $ticketSrcId,
                self::seconds((string) ($w['time_spent'] ?? '0')),
                (string) ($w['note'] ?? ''),
                isset($w['agent_id']) ? 'agent:' . $w['agent_id'] : null,
                self::date($w['executed_at'] ?? $w['created_at'] ?? null)
            );
        }

        // Real Freshservice answers carry the resolution as resolution_notes_html / resolution_notes on the ticket
        // (recorded 2026-11-18); the older guesses stay as fallbacks.
        $res = $main['resolution_notes_html'] ?? $main['resolution_notes'] ?? $main['resolution'] ?? null;
        if (is_array($res)) {
            $res = $res['content'] ?? $res['body'] ?? null;
        }
        $stats = (array) ($main['stats'] ?? []);

        $found = [];
        if ($approvals) {
            foreach ($this->all("/tickets/$id/approvals", 'approvals') as $a) {
                $found[] = self::approval($a, $ticketSrcId);
            }
        }

        return new TicketChildren(
            $notes,
            $tasks,
            $worklogs,
            $attachments,
            is_string($res) && trim($res) !== '' ? $res : null,
            self::date($stats['resolved_at'] ?? null),
            self::date($stats['closed_at'] ?? null),
            $found
        );
    }

    /**
     * A ticket approval. Recorded shape: approval_status (Requested / Approved / Rejected, or a code), approver_id,
     * user_id (who asked), latest_remark, level (1 to 4 on a multi-level chain), created_at, updated_at. Tolerant: an
     * unknown status stays 'requested' (never decided), a missing level is 1.
     *
     * @param array<string,mixed> $a
     */
    public static function approval(array $a, string $ticketSrcId): ExternalApproval
    {
        $raw = $a['approval_status_name'] ?? $a['status_name'] ?? $a['approval_status'] ?? $a['status'] ?? '';
        $word = strtolower(trim((string) $raw));
        $status = match (true) {
            in_array($word, ['approved', '2'], true) => 'approved',
            in_array($word, ['rejected', '3'], true) => 'rejected',
            default => 'requested',
        };
        $level = (int) ($a['level'] ?? $a['approval_level'] ?? 1);
        $by = $a['approver_id'] ?? $a['approver']['id'] ?? null;
        return new ExternalApproval(
            self::SOURCE,
            (string) $a['id'],
            $ticketSrcId,
            max(1, $level),
            $status,
            $by !== null ? (string) $by : null,
            isset($a['user_id']) ? (string) $a['user_id'] : null,
            self::text($a['latest_remark'] ?? $a['remark'] ?? ''),
            self::date($a['created_at'] ?? null),
            $status === 'requested' ? null : self::date($a['updated_at'] ?? null)
        );
    }

    public function downloadAttachment(ExternalAttachment $attachment, string $destPath): void
    {
        $this->client->download($attachment->url, $destPath);
    }

    /** @return list<array<string,mixed>> every page of a sub-list */
    private function all(string $endpoint, string $key): array
    {
        $out  = [];
        $page = 1;
        do {
            $r    = $this->client->get($endpoint, ['per_page' => FreshserviceClient::PER_PAGE, 'page' => $page]);
            $rows = $r['json'][$key] ?? [];
            array_push($out, ...$rows);
            $more = self::more($r, count($rows));
            $page++;
        } while ($more && $page <= self::MAX_PAGE);
        return $out;
    }

    /** @param array<string,mixed> $a */
    private static function attachment(array $a, string $ticketSrcId, ?string $noteSrcId): ExternalAttachment
    {
        return new ExternalAttachment(
            self::SOURCE,
            (string) $a['id'],
            $ticketSrcId,
            (string) ($a['name'] ?? 'attachment'),
            (string) ($a['attachment_url'] ?? ''),
            (int) ($a['size'] ?? 0),
            isset($a['content_type']) ? (string) $a['content_type'] : null,
            $noteSrcId
        );
    }

    /**
     * Is there another page? `meta.has_more` (sub-lists answer with it) is authoritative when present;
     * otherwise a Link header with rel="next", otherwise a full page. Pure.
     *
     * @param array{json: array<mixed>, link_next: bool} $r
     */
    public static function more(array $r, int $rows): bool
    {
        $meta = $r['json']['meta'] ?? null;
        if (is_array($meta) && array_key_exists('has_more', $meta)) {
            return (bool) $meta['has_more'];
        }
        return $r['link_next'] || $rows >= FreshserviceClient::PER_PAGE;
    }

    // -------------------------------------------------------------- helpers

    /**
     * Freshservice HTML-escapes some plain-text fields ("Network &amp; WAN"). Names that end up in GLPI or in a
     * category filter must be the real text. Pure.
     */
    public static function text(mixed $v): string
    {
        return trim(html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** ISO8601 -> UTC DateTimeImmutable, null when absent or unparsable. */
    public static function date(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** "hh:mm" (or "hh:mm:ss") -> seconds. Pure. */
    public static function seconds(string $hhmm): int
    {
        $p = array_map('intval', explode(':', trim($hhmm)));
        return ($p[0] ?? 0) * 3600 + ($p[1] ?? 0) * 60 + ($p[2] ?? 0);
    }
}
