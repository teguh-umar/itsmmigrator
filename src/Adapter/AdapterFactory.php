<?php

namespace GlpiPlugin\Itsmmigrator\Adapter;

use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceAdapter;
use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceClient;

/** Builds the adapter of a source row. The key arrives already decrypted and is passed on, never stored. */
final class AdapterFactory
{
    /** @param array<string,mixed> $source the sources row */
    public static function make(array $source, string $apiKey): SourceAdapterInterface
    {
        $ca = (string) ($source['ca_bundle_path'] ?? '');
        return match ((string) ($source['adapter'] ?? '')) {
            'freshservice' => new FreshserviceAdapter(new FreshserviceClient((string) $source['base_url'], $apiKey, null, null, 0.4, $ca !== '' ? $ca : null)),
            'sdp'          => throw new \RuntimeException('The ServiceDesk Plus adapter is not built yet (planned for M4)'),
            default        => throw new \RuntimeException('Unknown adapter "' . ($source['adapter'] ?? '') . '"'),
        };
    }
}
