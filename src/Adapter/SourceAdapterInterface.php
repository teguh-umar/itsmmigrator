<?php

namespace GlpiPlugin\Itsmmigrator\Adapter;

use GlpiPlugin\Itsmmigrator\Adapter\Dto\Page;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\TicketChildren;

/**
 * Writers only know normalized DTOs; adapters only know their source API (spec §1.1).
 * Adding a third source later = one new adapter. Adapters are GET-only: sources are read-only.
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
     * One page of one module, pages numbered from 1 and requested in order. $since is UTC (null =
     * everything). Items are the module's DTOs.
     */
    public function fetchPage(string $module, int $page, ?\DateTimeImmutable $since): Page;

    /** Notes, tasks, worklogs, attachments and the resolution of one ticket. */
    public function fetchTicketChildren(string $ticketSrcId): TicketChildren;

    /** Download one attachment into $destPath (a file under GLPI_TMP_DIR). */
    public function downloadAttachment(Dto\ExternalAttachment $attachment, string $destPath): void;
}
