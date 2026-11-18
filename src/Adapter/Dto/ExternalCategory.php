<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Dto;

/** An ITIL category as a tree path: ['Hardware', 'Laptop', 'Battery']. */
final class ExternalCategory extends ExternalItem
{
    /** @param list<string> $path names from the root down to this category */
    public function __construct(
        string $source,
        string $srcId,
        public readonly array $path,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        parent::__construct($source, 'itilcategory', $srcId, null, $updatedAt);
    }

    public function name(): string
    {
        return $this->path === [] ? '' : (string) end($this->path);
    }
}
