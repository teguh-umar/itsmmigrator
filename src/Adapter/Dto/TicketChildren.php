<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Dto;

/** Everything hanging off one ticket, fetched together with it. */
final class TicketChildren
{
    /**
     * @param list<ExternalNote>       $notes
     * @param list<ExternalTask>       $tasks
     * @param list<ExternalWorklog>    $worklogs
     * @param list<ExternalAttachment> $attachments  of the ticket itself and of its notes
     * @param string|null              $resolution   solution text, when the source has one
     * @param \DateTimeImmutable|null  $resolvedAt
     * @param \DateTimeImmutable|null  $closedAt
     */
    public function __construct(
        public readonly array $notes = [],
        public readonly array $tasks = [],
        public readonly array $worklogs = [],
        public readonly array $attachments = [],
        public readonly ?string $resolution = null,
        public readonly ?\DateTimeImmutable $resolvedAt = null,
        public readonly ?\DateTimeImmutable $closedAt = null,
    ) {
    }
}
