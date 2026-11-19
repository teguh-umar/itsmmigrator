<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Dto;

/**
 * One approval request of a ticket (a Freshservice ticket approval). Becomes a TicketValidation inside the approval
 * step "Level N". `status`: 'requested' | 'approved' | 'rejected'. `level` 1..n (the order of a multi-level chain; 1 when
 * the source has no levels).
 */
final class ExternalApproval extends ExternalItem
{
    public function __construct(
        string $source,
        string $srcId,
        public readonly string $ticketSrcId,
        public readonly int $level,
        public readonly string $status,
        public readonly ?string $approverSrcId = null,
        public readonly ?string $requesterSrcId = null,
        public readonly string $remark = '',
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $decidedAt = null,
    ) {
        parent::__construct($source, 'approval', $srcId, null, $createdAt);
    }
}
