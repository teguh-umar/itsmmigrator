<?php

namespace GlpiPlugin\Itsmmigrator\Adapter;

use GlpiPlugin\Itsmmigrator\Adapter\Dto\Page;

/**
 * Writers only know normalized DTOs; adapters only know their source API (spec §1.1).
 * Adding a third source later = one new adapter. Adapters are GET-only: sources are read-only.
 *
 * fetchTicketChildren() and downloadAttachment() arrive with the ticket DTOs in M3.
 */
interface SourceAdapterInterface
{
    /** 'freshservice' | 'sdp' */
    public function getSourceKey(): string;

    /** @param string $module users, groups, categories, kb, assets, tickets, problems, changes */
    public function supports(string $module): bool;

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array;

    /**
     * One page of one module. $since is UTC (null = everything). Items are the module's DTOs.
     */
    public function fetchPage(string $module, int $page, ?\DateTimeImmutable $since): Page;
}
