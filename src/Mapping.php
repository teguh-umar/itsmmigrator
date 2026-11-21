<?php

namespace GlpiPlugin\Itsmmigrator;

/**
 * Pure value mapping shared by the writers (spec §4): source labels -> GLPI values through the
 * per-source JSON maps (which override the defaults), dates, and the text hash used for dedup.
 */
final class Mapping
{
    // GLPI Ticket status values.
    public const NEW      = 1;
    public const ASSIGNED = 2;
    public const WAITING  = 4;
    public const SOLVED   = 5;
    public const CLOSED   = 6;
    public const APPROVAL = 10; // GLPI 11 ticket status "Approval"

    /** Forward order of GLPI ticket statuses (Approval sits after Pending, before Solved). */
    private const STATUS_RANK = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 10 => 5, 5 => 6, 6 => 7];
    private const STATUS_NAME = [1 => 'New', 2 => 'Processing (assigned)', 3 => 'Processing (planned)', 4 => 'Pending', 10 => 'Approval', 5 => 'Solved', 6 => 'Closed'];

    /**
     * The import rule for a ticket that is already in GLPI: its status only moves FORWARD, and only while it is still
     * open or pending (never from Solved/Closed, never backwards, never an unknown status). Pure.
     */
    public static function advances(int $current, int $target): bool
    {
        $c = self::STATUS_RANK[$current] ?? null;
        $t = self::STATUS_RANK[$target] ?? null;
        return $c !== null && $t !== null && $c < self::STATUS_RANK[self::SOLVED] && $t > $c;
    }

    public static function statusName(int $status): string
    {
        return self::STATUS_NAME[$status] ?? ('status ' . $status);
    }

    public const DEFAULT_STATUS = [
        'open' => 'assigned_or_new', 'in progress' => 'assigned_or_new', 'pending' => self::WAITING, 'on hold' => self::WAITING,
        'waiting on approval' => self::APPROVAL, 'resolved' => self::SOLVED, 'closed' => self::CLOSED, 'cancelled' => self::CLOSED,
    ];
    public const DEFAULT_PRIORITY = ['low' => 2, 'medium' => 3, 'high' => 4, 'urgent' => 5];
    public const DEFAULT_TYPE     = ['incident' => 1, 'service request' => 2, 'request' => 2];

    /**
     * @param array<string,mixed> $custom the source's status_map (label => GLPI status), may be empty
     * @return array{value:int, known:bool} known=false when the label is in neither map (a warning is due)
     */
    public static function status(string $label, bool $hasAssignee, array $custom = []): array
    {
        $key = mb_strtolower(trim($label));
        $custom = self::lower($custom);
        $v = $custom[$key] ?? self::DEFAULT_STATUS[$key] ?? null;
        if ($v === null) {
            // An account's own wording ("Waiting on Approval", "On hold - vendor"): a best guess by words, still reported
            // as unmapped so the administrator can set it in status_map.
            return ['value' => self::guessStatus($key, $hasAssignee), 'known' => false];
        }
        if ($v === 'assigned_or_new') {
            return ['value' => $hasAssignee ? self::ASSIGNED : self::NEW, 'known' => true];
        }
        return ['value' => (int) $v, 'known' => true];
    }

    /** Best guess for a status label nobody mapped. Pure. */
    public static function guessStatus(string $lowerLabel, bool $hasAssignee): int
    {
        return match (true) {
            str_contains($lowerLabel, 'wait'), str_contains($lowerLabel, 'pend'), str_contains($lowerLabel, 'hold') => self::WAITING,
            str_contains($lowerLabel, 'resolv'), str_contains($lowerLabel, 'solv'), str_contains($lowerLabel, 'complet') => self::SOLVED,
            str_contains($lowerLabel, 'clos'), str_contains($lowerLabel, 'cancel'), str_contains($lowerLabel, 'reject') => self::CLOSED,
            default => $hasAssignee ? self::ASSIGNED : self::NEW,
        };
    }
    /** @param array<string,mixed> $custom @return array{value:int, known:bool} unknown -> Medium (3) */
    public static function priority(string $label, array $custom = []): array
    {
        $key = mb_strtolower(trim($label));
        $v   = self::lower($custom)[$key] ?? self::DEFAULT_PRIORITY[$key] ?? null;
        return $v === null ? ['value' => 3, 'known' => false] : ['value' => (int) $v, 'known' => true];
    }

    /** @param array<string,mixed> $custom @return array{value:int, known:bool} unknown -> Incident (1) */
    public static function type(string $label, array $custom = []): array
    {
        $key = mb_strtolower(trim($label));
        $v   = self::lower($custom)[$key] ?? self::DEFAULT_TYPE[$key] ?? null;
        return $v === null ? ['value' => 1, 'known' => false] : ['value' => (int) $v, 'known' => true];
    }

    /** GLPI task state from the normalized task state: todo / in_progress -> 1 (todo), done -> 2. */
    public static function taskState(string $state): int
    {
        return $state === 'done' ? 2 : 1;
    }

    /** UTC -> the PHP (GLPI) timezone as `Y-m-d H:i:s`; null stays null. Never '' (strict TIMESTAMP). */
    public static function localDate(?\DateTimeImmutable $utc): ?string
    {
        return $utc?->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /** Tags stripped, entities decoded, whitespace collapsed, lower-cased (spec §6.3). */
    public static function normalizeText(string $html): string
    {
        $t = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $t) ?? ''));
    }

    public static function contentHash(string $html): string
    {
        return sha1(self::normalizeText($html));
    }

    /** `[FS#1001] Subject` / `[SDP#...] Subject` (spec §4). */
    public static function ticketTitle(string $prefix, string $displayId, string $subject): string
    {
        $title = sprintf('[%s#%s] %s', $prefix, $displayId, trim($subject) === '' ? '(no subject)' : trim($subject));
        // glpi_tickets.name is 255 characters; the [prefix#id] part is the natural key and stays whole.
        return mb_strlen($title) > 255 ? mb_substr($title, 0, 254) . '…' : $title;
    }

    /** @param array<string,mixed> $m @return array<string,mixed> keys lower-cased */
    private static function lower(array $m): array
    {
        $out = [];
        foreach ($m as $k => $v) {
            $out[mb_strtolower(trim((string) $k))] = $v;
        }
        return $out;
    }
}
