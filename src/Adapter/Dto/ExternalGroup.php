<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Dto;

final class ExternalGroup extends ExternalItem
{
    /** @param list<string> $memberSrcIds source ids of the member users */
    public function __construct(
        string $source,
        string $srcId,
        public readonly string $name,
        public readonly array $memberSrcIds = [],
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        parent::__construct($source, 'group', $srcId, null, $updatedAt);
    }
}
