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

    public const DEFAULT_STATUS = [
        'open' => 'assigned_or_new', 'in progress' => 'assigned_or_new', 'pending' => self::WAITING, 'on hold' => self::WAITING,
        'resolved' => self::SOLVED, 'closed' => self::CLOSED, 'cancelled' => self::CLOSED,
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
            return ['value' => $hasAssignee ? self::ASSIGNED : self::NEW, 'known' => false];
        }
        if ($v === 'assigned_or_new') {
            return ['value' => $hasAssignee ? self::ASSIGNED : self::NEW, 'known' => true];
        }
        return ['value' => (int) $v, 'known' => true];
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
        return sprintf('[%s#%s] %s', $prefix, $displayId, trim($subject) === '' ? '(no subject)' : trim($subject));
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
