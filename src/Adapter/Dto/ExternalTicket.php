<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Dto;

/**
 * A ticket, normalized. Status, priority and type are SOURCE LABELS ("Open", "High", "Incident");
 * the per-source maps in the sources row turn them into GLPI values (spec §4).
 * Dates are UTC.
 */
final class ExternalTicket extends ExternalItem
{
    /**
     * @param list<string> $categoryPath e.g. ['Hardware', 'Laptop']
     */
    public function __construct(
        string $source,
        string $srcId,
        public readonly string $subject,
        public readonly string $content,
        public readonly string $status,
        public readonly string $priority,
        public readonly string $type,
        public readonly ?string $requesterSrcId = null,
        public readonly ?string $assigneeSrcId = null,
        public readonly ?string $groupSrcId = null,
        public readonly array $categoryPath = [],
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $dueAt = null,
        ?string $displayId = null,
        ?\DateTimeImmutable $updatedAt = null,
        ?string $originRef = null,
    ) {
        parent::__construct($source, 'ticket', $srcId, $displayId ?? $srcId, $updatedAt, $originRef);
    }
}
