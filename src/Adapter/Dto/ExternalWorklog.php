<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Dto;

/** Time spent on a ticket. Becomes a TicketTask carrying `actiontime` (seconds). */
final class ExternalWorklog extends ExternalItem
{
    public function __construct(
        string $source,
        string $srcId,
        public readonly string $ticketSrcId,
        public readonly int $seconds,
        public readonly string $note,
        public readonly ?string $authorSrcId = null,
        public readonly ?\DateTimeImmutable $executedAt = null,
    ) {
        parent::__construct($source, 'worklog', $srcId, null, $executedAt);
    }
}
