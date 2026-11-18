<?php

namespace GlpiPlugin\Itsmmigrator;

/**
 * "Import only these categories" (an early-phase scope limit). A filter is a list of category paths written
 * with ' > ' between levels, e.g. "Hardware" (everything below it) or "Hardware > Laptop". Case and spacing do
 * not matter. No filter = everything. Pure.
 */
final class CategoryFilter
{
    /**
     * Normalise the source option or CLI value: an array or a text with one path per line (or ';' / ',' is NOT a
     * separator, because a category name may contain a comma). Empty entries are dropped.
     *
     * @return list<list<string>> each filter as its levels, lower-cased
     */
    public static function parse(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R/', $value) ?: [];
        }
        $out = [];
        foreach ((array) $value as $line) {
            $levels = self::levels((string) $line);
            if ($levels !== []) {
                $out[] = $levels;
            }
        }
        return $out;
    }

    /** @return list<string> */
    public static function levels(string $path): array
    {
        $levels = [];
        foreach (explode('>', $path) as $l) {
            $l = mb_strtolower(trim(preg_replace('/\s+/u', ' ', html_entity_decode($l, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''));
            if ($l !== '') {
                $levels[] = $l;
            }
        }
        return $levels;
    }

    /**
     * May a TICKET with this category path be imported? With a filter, a ticket with no category is not.
     *
     * @param list<list<string>> $filters
     * @param list<string> $path the ticket's levels
     */
    public static function allowsTicket(array $filters, array $path): bool
    {
        if ($filters === []) {
            return true;
        }
        $p = self::levels(implode('>', $path));
        foreach ($filters as $f) {
            if (count($p) >= count($f) && array_slice($p, 0, count($f)) === $f) {
                return true;
            }
        }
        return false;
    }

    /**
     * May a CATEGORY of the category tree be created? A category is needed when it is under a filter
     * or is a parent of one ("Hardware" is needed for "Hardware > Laptop").
     *
     * @param list<list<string>> $filters
     * @param list<string> $path
     */
    public static function allowsCategory(array $filters, array $path): bool
    {
        if ($filters === []) {
            return true;
        }
        $p = self::levels(implode('>', $path));
        foreach ($filters as $f) {
            $n = min(count($p), count($f));
            if ($n > 0 && array_slice($p, 0, $n) === array_slice($f, 0, $n)) {
                return true;
            }
        }
        return false;
    }
}
