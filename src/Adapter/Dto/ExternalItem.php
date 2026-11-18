<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Dto;

/**
 * Common fields of every normalized item (spec §1.1). `srcId` is a string: SDP ids are long numbers.
 * `updatedAt` is UTC. `originRef` is the cross-source key, e.g. `freshservice:ticket:12345` (§6).
 */
abstract class ExternalItem
{
    public function __construct(
        public readonly string $source,
        public readonly string $srcType,
        public readonly string $srcId,
        public readonly ?string $displayId = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
        public readonly ?string $originRef = null,
    ) {
    }
}
