<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Dto;

/** A conversation entry / note on a ticket. Becomes an ITILFollowup. */
final class ExternalNote extends ExternalItem
{
    public function __construct(
        string $source,
        string $srcId,
        public readonly string $ticketSrcId,
        public readonly string $body,
        public readonly bool $isPrivate,
        public readonly ?string $authorSrcId = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
    ) {
        parent::__construct($source, 'note', $srcId, null, $createdAt);
    }
}
