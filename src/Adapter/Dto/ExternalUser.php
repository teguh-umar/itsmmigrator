<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Dto;

/** A requester or technician from a source. Freshservice has no login; SDP has `login_name`. */
final class ExternalUser extends ExternalItem
{
    /**
     * @param list<string> $emails every e-mail the source knows, primary first
     */
    public function __construct(
        string $source,
        string $srcId,
        public readonly string $name = '',
        public readonly array $emails = [],
        public readonly ?string $login = null,
        public readonly bool $isTechnician = false,
        ?string $displayId = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        parent::__construct($source, 'user', $srcId, $displayId, $updatedAt);
    }
}
