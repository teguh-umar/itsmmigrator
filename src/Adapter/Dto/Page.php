<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Dto;

/**
 * One page of a module's items, as the adapter returns it.
 *
 * `cursor` is the opaque position of the NEXT page for modules whose paging is not just "page + 1"
 * (Freshservice users are agents first, then requesters). The caller passes it back to fetchPage()
 * so no paging state lives in the adapter object: a web run fetches one page per request.
 */
final class Page
{
    /** @param list<ExternalItem> $items */
    public function __construct(
        public readonly array $items,
        public readonly bool $hasMore,
        public readonly ?string $cursor = null,
        /** @var list<string> things the caller should log (never data: only counts and timestamps) */
        public readonly array $notes = [],
    ) {
    }
}
