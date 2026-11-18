<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Dto;

/** One page of a module's items, as the adapter returns it. */
final class Page
{
    /** @param list<ExternalItem> $items */
    public function __construct(
        public readonly array $items,
        public readonly bool $hasMore,
    ) {
    }
}
