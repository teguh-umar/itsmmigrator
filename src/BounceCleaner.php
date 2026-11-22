<?php

namespace GlpiPlugin\Itsmmigrator;

/**
 * Cleans a mail-bounce notice ("Undeliverable", "Delivery has failed to these recipients") that a source stored as the body of
 * a ticket or a note. The body is cut at the line "Diagnostic information for administrators" (or, when that line is absent, at
 * "Original message headers"); everything before it stays (the requester line, the original message From/To/Date/Subject, the
 * "Delivery has failed" statement and its one-line reason), minus the "CAUTION:" banner. Diagnostic data and the headers
 * block, often tens of kilobytes of encoded text, are dropped and a short italic note says so.
 *
 * Pure: no GLPI, no database. Used by the importer (new tickets and notes) and by `plugins:itsmmigrator:clean-bounces`
 * (tickets already imported).
 */
final class BounceCleaner
{
    /** Marker text of the note appended to a cleaned body; also how an already-cleaned body is recognised. */
    public const REMOVED = 'Bounce details removed by the migration cleanup';

    private const CUTS = ['Diagnostic information for administrators', 'Original message headers'];

    /** True for a bounce notice that has not been cleaned yet. */
    public static function isBounce(string $html): bool
    {
        if ($html === '' || str_contains($html, self::REMOVED)) {
            return false;
        }
        $hasCut = false;
        foreach (self::CUTS as $c) {
            if (stripos($html, $c) !== false) {
                $hasCut = true;
                break;
            }
        }
        if (!$hasCut) {
            return false;
        }
        foreach (['Delivery has failed to these recipients', 'Final-Recipient:', 'Undeliverable'] as $s) {
            if (stripos($html, $s) !== false) {
                return true;
            }
        }
        return false;
    }

    /** The cleaned HTML, or null when the body is not a bounce notice (or has nothing to cut). */
    public static function clean(string $html): ?string
    {
        if (!self::isBounce($html)) {
            return null;
        }
        $lines = self::lines($html);
        foreach (self::CUTS as $cut) {
            $keep  = [];
            $found = false;
            foreach ($lines as $l) {
                if (stripos($l, $cut) !== false) {
                    $found = true;
                    break;
                }
                if (str_starts_with(ltrim($l), 'CAUTION:')) {
                    continue;
                }
                $keep[] = $l;
            }
            if (!$found) {
                continue;
            }
            $txt = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $keep)));
            if ($txt === '') {
                $txt = '(no readable text left)';
            }
            $paras = array_map(
                fn (string $p): string => '<p>' . implode('<br>', array_map(fn (string $x): string => htmlspecialchars($x, ENT_QUOTES, 'UTF-8'), explode("\n", trim($p)))) . '</p>',
                preg_split("/\n\n/", $txt) ?: [$txt]
            );
            return implode('', $paras) . '<p><i>[' . self::REMOVED . ': diagnostic data and the original message headers.]</i></p>';
        }
        return null;
    }

    /** @return list<string> the visible text of the HTML, one entry per line */
    private static function lines(string $html): array
    {
        $t = (string) preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>#i', "\n", $html);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return array_map('rtrim', preg_split('/\R/', str_replace("\xC2\xA0", ' ', $t)) ?: []);
    }
}
