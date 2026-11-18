<?php

namespace GlpiPlugin\Itsmmigrator;

/** Another run holds the global lock. The message names the owner (spec §5 rule 4). */
final class LockException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $stale = false)
    {
        parent::__construct($message);
    }
}
