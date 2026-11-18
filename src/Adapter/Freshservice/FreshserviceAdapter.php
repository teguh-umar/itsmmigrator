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

    /** users module = agents first, then requesters. Pages are requested in order, so a cursor is enough. */
    private string $userPhase = 'agents';
    private int $userPage = 1;

    public function __construct(private readonly FreshserviceClient $client)
    {
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

    public function fetchPage(string $module, int $page, ?\DateTimeImmutable $since): Page
    {
        return match ($module) {
            'users'      => $this->usersPage($page),
            'groups'     => $this->groupsPage($page),
            'categories' => $this->categoriesPage($page),
            'tickets'    => $this->ticketsPage($page, $since),
            default      => throw new \InvalidArgumentException("Module $module is not supported by Freshservice"),
        };
    }

    // ---------------------------------------------------------------- users

    private function usersPage(int $page): Page
    {
        if ($page === 1) {
            $this->userPhase = 'agents';
            $this->userPage  = 1;
        }
        $isAgent = $this->userPhase === 'agents';
        $r       = $this->client->get($isAgent ? '/agents' : '/requesters', ['per_page' => FreshserviceClient::PER_PAGE, 'page' => $this->userPage]);
        $rows    = $r['json'][$isAgent ? 'agents' : 'requesters'] ?? [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = $isAgent ? self::agent($row) : self::requester($row);
        }

        $more = $r['link_next'] || count($rows) >= FreshserviceClient::PER_PAGE;
        if ($more) {
            $this->userPage++;
            return new Page($items, true);
        }
        if ($isAgent) {
            $this->userPhase = 'requesters';
            $this->userPage  = 1;
            return new Page($items, true); // requesters follow
        }
        return new Page($items, false);
    }

    /** @param array<string,mixed> $a */
    public static function agent(array $a): ExternalUser
    {
        $id = (string) $a['id'];
        return new ExternalUser(
            self::SOURCE,
            'agent:' . $id,
            trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')),
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
            trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
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
            // Membership is not read: the v2 group list does not reliably carry it (unverified), and
            // adding members is optional in this build.
            $out[] = new ExternalGroup(self::SOURCE, (string) $g['id'], (string) $g['name'], [], self::date($g['updated_at'] ?? null));
        }
        return new Page($out, $r['link_next'] || count($rows) >= FreshserviceClient::PER_PAGE);
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
                $label = $choice;
            } elseif (is_array($choice) && array_is_list($choice) && isset($choice[0]) && is_string($choice[0])) {
                $label = $choice[0];
                $kids  = is_array($choice[1] ?? null) ? $choice[1] : [];
            } elseif (is_array($choice)) {
                $label = (string) ($choice['label'] ?? $choice['value'] ?? '');
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

    private function ticketsPage(int $page, ?\DateTimeImmutable $since): Page
    {
        if ($page > self::MAX_PAGE) {
            throw new ClientException('Freshservice stops paging after ' . self::MAX_PAGE . ' pages: import in windows with --since', 0, 'limit');
        }
        $from = ($since ?? new \DateTimeImmutable('2010-01-01T00:00:00Z'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        // updated_since is mandatory: without it the list only returns the last 30 days.
        $r    = $this->client->get('/tickets', [
            'updated_since' => $from,
            'order_by'      => 'updated_at',
            'order_type'    => 'asc',
            'per_page'      => FreshserviceClient::PER_PAGE,
            'page'          => $page,
        ]);
        $rows  = $r['json']['tickets'] ?? [];
        $items = [];
        foreach ($rows as $t) {
            $items[] = self::ticket($t);
        }
        return new Page($items, $r['link_next'] || count($rows) >= FreshserviceClient::PER_PAGE);
    }

    /** @param array<string,mixed> $t */
    public static function ticket(array $t): ExternalTicket
    {
        $path = array_values(array_filter([(string) ($t['category'] ?? ''), (string) ($t['sub_category'] ?? ''), (string) ($t['item_category'] ?? '')], static fn ($s) => $s !== ''));
        return new ExternalTicket(
            self::SOURCE,
            (string) $t['id'],
            (string) ($t['subject'] ?? ''),
            (string) ($t['description'] ?? ($t['description_text'] ?? '')),
            self::STATUS[(int) ($t['status'] ?? 0)] ?? 'Open',
            self::PRIORITY[(int) ($t['priority'] ?? 0)] ?? 'Medium',
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

    public function fetchTicketChildren(string $ticketSrcId): TicketChildren
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

        $res = $main['resolution'] ?? null;
        if (is_array($res)) {
            $res = $res['content'] ?? $res['body'] ?? null;
        }
        $stats = (array) ($main['stats'] ?? []);

        return new TicketChildren(
            $notes,
            $tasks,
            $worklogs,
            $attachments,
            is_string($res) && trim($res) !== '' ? $res : null,
            self::date($stats['resolved_at'] ?? null),
            self::date($stats['closed_at'] ?? null)
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
            $more = $r['link_next'] || count($rows) >= FreshserviceClient::PER_PAGE;
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

    // -------------------------------------------------------------- helpers

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
