<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Dto;

/** A ticket task. Becomes a TicketTask. `state`: 'todo' | 'in_progress' | 'done'. */
final class ExternalTask extends ExternalItem
{
    public function __construct(
        string $source,
        string $srcId,
        public readonly string $ticketSrcId,
        public readonly string $title,
        public readonly string $body,
        public readonly string $state,
        public readonly ?string $assigneeSrcId = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
    ) {
        parent::__construct($source, 'task', $srcId, null, $createdAt);
    }
}
