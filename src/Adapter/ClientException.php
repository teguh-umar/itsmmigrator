<?php

namespace GlpiPlugin\Itsmmigrator\Adapter;

/** A source API call failed. The message never contains the API key. */
final class ClientException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $reason = 'http')
    {
        parent::__construct($message);
    }
}
