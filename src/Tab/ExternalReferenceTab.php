<?php

namespace GlpiPlugin\Itsmmigrator\Tab;

use CommonGLPI;
use GlpiPlugin\Itsmmigrator\MapRepository;
use GlpiPlugin\Itsmmigrator\RightDefinition;
use Session;

/**
 * "External references" tab on Ticket, Problem and Change: every map row that points at the item
 * (source, id in the source, a link back to it, how it got here, when it last changed).
 * Registered with Plugin::registerClass(..., ['addtabon' => ['Ticket', 'Problem', 'Change']]).
 */
class ExternalReferenceTab extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('External references', 'itsmmigrator');
    }

    /** Instance method on purpose: core declares it non-static. Shown only when the item has references. */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ((int) $withtemplate !== 0 || !self::allowed() || !in_array($item::class, ['Ticket', 'Problem', 'Change'], true)) {
            return '';
        }
        $n = count(self::rows($item::class, (int) $item->getID()));
        return $n > 0 ? self::createTabEntry(self::getTypeName($n), $n, $item::class, 'ti ti-database-import') : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!self::allowed()) {
            return false;
        }
        self::render($item::class, (int) $item->getID());
        return true;
    }

    private static function allowed(): bool
    {
        return (bool) (Session::haveRight(RightDefinition::RUN, READ) || Session::haveRight(RightDefinition::CONFIG, READ));
    }

    /**
     * Rows for one item, children (followups, tasks, documents) left out: only the item's own references.
     *
     * @return list<array<string,mixed>>
     */
    public static function rows(string $itemtype, int $id): array
    {
        $out = [];
        foreach (MapRepository::findByItem($itemtype, $id) as $r) {
            if (in_array($r['src_type'], ['ticket', 'problem', 'change'], true)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * Link back to the item in its own tool, or null when it cannot be built. Pure.
     * Freshservice: https://{domain}/a/tickets/{id}. ServiceDesk Plus: {base}/WorkOrder.do?woMode=viewWO&woID={id}
     * (the SDP form is from the spec and still to be verified on the installed build).
     */
    public static function deepLink(string $adapter, string $baseUrl, string $srcType, string $srcId): ?string
    {
        $base = rtrim($baseUrl, '/');
        if (!preg_match('#^https://#i', $base) || $srcId === '') {
            return null;
        }
        return match (true) {
            $adapter === 'freshservice' && $srcType === 'ticket' => $base . '/a/tickets/' . rawurlencode($srcId),
            $adapter === 'sdp' && $srcType === 'ticket'          => $base . '/WorkOrder.do?woMode=viewWO&woID=' . rawurlencode($srcId),
            default                                              => null,
        };
    }

    private static function render(string $itemtype, int $id): void
    {
        global $DB;
        $rows    = self::rows($itemtype, $id);
        $sources = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'adapter', 'base_url'], 'FROM' => 'glpi_plugin_itsmmigrator_sources']) as $s) {
            $sources[(int) $s['id']] = $s;
        }

        $owned = array_filter($rows, static fn (array $r): bool => (int) $r['sync_owned'] === 1);
        if ($owned !== []) {
            $names = array_unique(array_map(static fn (array $r): string => (string) ($sources[(int) $r['sources_id']]['name'] ?? $r['source']), $owned));
            echo '<div class="alert alert-info">' . htmlescape(sprintf(__('Managed in %s: this is a read-only mirror. Changes must be made there.', 'itsmmigrator'), implode(', ', $names))) . '</div>';
        }

        echo '<div class="table-responsive"><table class="table table-hover card-table"><thead><tr>';
        foreach ([__('Source', 'itsmmigrator'), __('Id in the source', 'itsmmigrator'), __('Link', 'itsmmigrator'), __('How it got here', 'itsmmigrator'), __('Status', 'itsmmigrator'), __('Last change', 'itsmmigrator'), __('Sync-owned', 'itsmmigrator')] as $h) {
            echo '<th>' . htmlescape($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $s    = $sources[(int) $r['sources_id']] ?? null;
            $link = $s !== null ? self::deepLink((string) $s['adapter'], (string) $s['base_url'], (string) $r['src_type'], (string) $r['src_id']) : null;
            echo '<tr>';
            echo '<td>' . htmlescape((string) ($s['name'] ?? $r['source'])) . '</td>';
            echo '<td>' . htmlescape((string) ($r['display_id'] ?? $r['src_id'])) . '</td>';
            echo '<td>' . ($link !== null ? '<a href="' . htmlescape($link) . '" target="_blank" rel="noopener noreferrer">' . htmlescape(__('Open', 'itsmmigrator')) . '</a>' : '-') . '</td>';
            echo '<td>' . htmlescape((string) $r['action']) . '</td>';
            echo '<td>' . htmlescape((string) $r['status']) . '</td>';
            echo '<td>' . htmlescape((string) ($r['date_mod'] ?? '')) . '</td>';
            echo '<td>' . ((int) $r['sync_owned'] === 1 ? htmlescape(__('Yes')) : htmlescape(__('No'))) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
