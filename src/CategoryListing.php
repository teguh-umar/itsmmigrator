<?php

namespace GlpiPlugin\Itsmmigrator;

use GlpiPlugin\Itsmmigrator\Adapter\AdapterFactory;

/**
 * Read-only listing of a source's category tree, for the administrator who has to choose which categories to
 * import. Paths only: no ticket, no user, no count. One GET to the source (the form listing), nothing is written
 * and nothing is logged: the answer goes to the logged-in administrator's page and nowhere else.
 */
final class CategoryListing
{
    /** Upper bound on the list sent to the browser. */
    public const MAX = 3000;

    /**
     * @param list<\GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalCategory> $items
     * @return list<string> "A > B > C", sorted, unique, at most MAX
     */
    public static function paths(array $items): array
    {
        $out = [];
        foreach ($items as $c) {
            $path = implode(' > ', array_map('strval', $c->path));
            if (trim($path) !== '') {
                $out[$path] = true;
            }
        }
        $paths = array_keys($out);
        sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
        return array_slice($paths, 0, self::MAX);
    }

    /** @return array{success: bool, message?: string, paths?: list<string>, truncated?: bool} */
    public static function forSource(int $sourcesId): array
    {
        $source = new Source();
        if (!$source->getFromDB($sourcesId)) {
            return ['success' => false, 'message' => __('Unknown source.', 'itsmmigrator')];
        }
        if ($source->apiKey() === '') {
            return ['success' => false, 'message' => __('No API key is stored for this source.', 'itsmmigrator')];
        }
        try {
            $adapter = AdapterFactory::make($source->fields, $source->apiKey());
            if (!$adapter->supports('categories')) {
                return ['success' => false, 'message' => __('This adapter has no category tree.', 'itsmmigrator')];
            }
            $page  = $adapter->fetchPage('categories', 1, null);
            $paths = self::paths($page->items);
            return ['success' => true, 'paths' => $paths, 'truncated' => count($page->items) > self::MAX];
        } catch (\Throwable $e) {
            // The adapter's messages never contain the key; nothing else is added.
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
