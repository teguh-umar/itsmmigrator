<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Dto;

/**
 * A file attached to a ticket or a note. `url` is where the adapter downloads it from; for
 * Freshservice that is a short-lived S3 link, so it must be used right after the parent is fetched.
 */
final class ExternalAttachment extends ExternalItem
{
    public function __construct(
        string $source,
        string $srcId,
        public readonly string $ticketSrcId,
        public readonly string $name,
        public readonly string $url,
        public readonly int $size = 0,
        public readonly ?string $contentType = null,
        public readonly ?string $noteSrcId = null,
    ) {
        parent::__construct($source, 'document', $srcId);
    }
}
