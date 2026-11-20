<?php

namespace GlpiPlugin\Itsmmigrator;

/**
 * Links an imported ticket to the GLPI asset it is about, for tickets the network monitor (NMS) opened: the monitor
 * writes the device name and its address in the ticket ("Device: <name> <ip> Category: ..."), the source keeps no
 * asset record. The asset must already exist in GLPI (an exact name, case-insensitive, in the ticket's entity or one it
 * can see); the plugin never creates an asset. When there is none, or more than one, a private note says what the
 * monitor named, so nothing is guessed.
 */
final class AssetLinker
{
    public const MARK = 'Asset (monitor):';

    /**
     * The device a monitor ticket is about. Pure.
     *
     * @return array{name:string,ip:string}|null
     */
    public static function parse(string $subject, string $content): ?array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($content, ENT_QUOTES | ENT_HTML5))));
        $subj = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($subject, ENT_QUOTES | ENT_HTML5)));
        if (stripos($subj . ' ' . $text, 'NMS') === false) {
            return null;
        }
        $ip = '(?:\d{1,3}\.){3}\d{1,3}';
        if (preg_match('/Device:\s*(.+?)\s+(' . $ip . ')\s+Category:/iu', $text, $m)
            || preg_match('/^(?:\[[^\]]*\]\s*)?NMS\s*-\s*.*?layanan\s+(.+?)\s+-\s+(' . $ip . ')\b/iu', $subj, $m)) {
            $name = trim($m[1]);
            return $name === '' ? null : ['name' => $name, 'ip' => $m[2]];
        }
        return null;
    }

    /**
     * The private note for a device with no single asset in GLPI. Pure.
     *
     * @param array{name:string,ip:string} $device
     */
    public static function note(array $device, int $found): string
    {
        $why = $found > 1 ? 'more than one asset has that name, so none was linked' : 'no asset with that name exists in GLPI';
        return '<p><b>' . self::MARK . '</b> ' . htmlspecialchars($device['name'], ENT_QUOTES) . ' (' . htmlspecialchars($device['ip'], ENT_QUOTES) . '): ' . $why . '.</p>';
    }

    /**
     * Link (or note) one ticket. Safe to repeat: a link that exists is kept, a note is added once.
     *
     * @return string none (not a monitor ticket) | linked | already | missing | ambiguous
     */
    public static function apply(int $ticketId, bool $dryRun = false): string
    {
        global $DB, $CFG_GLPI;
        $t = $DB->request(['SELECT' => ['name', 'content', 'entities_id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => $ticketId]])->current();
        if (!$t) {
            return 'none';
        }
        $device = self::parse((string) $t['name'], (string) $t['content']);
        if ($device === null) {
            return 'none';
        }
        $hits = [];
        foreach ((array) ($CFG_GLPI['asset_types'] ?? []) as $type) {
            if (!is_a($type, \CommonDBTM::class, true)) {
                continue;
            }
            $table = getTableForItemType($type);
            if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'name')) {
                continue;
            }
            $where = ['name' => $device['name']] + getEntitiesRestrictCriteria($table, 'entities_id', (int) $t['entities_id'], true);
            if ($DB->fieldExists($table, 'is_deleted')) {
                $where['is_deleted'] = 0;
            }
            if ($DB->fieldExists($table, 'is_template')) {
                $where['is_template'] = 0;
            }
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $where]) as $r) {
                $hits[] = [$type, (int) $r['id']];
            }
        }
        if (count($hits) === 1) {
            [$type, $id] = $hits[0];
            $have = countElementsInTable('glpi_items_tickets', ['tickets_id' => $ticketId, 'itemtype' => $type, 'items_id' => $id]) > 0;
            if (!$have && !$dryRun) {
                (new \Item_Ticket())->add(['tickets_id' => $ticketId, 'itemtype' => $type, 'items_id' => $id, '_disablenotif' => true]);
            }
            return $have ? 'already' : 'linked';
        }
        if (!$dryRun && countElementsInTable('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $ticketId, ['content' => ['LIKE', '%' . self::MARK . '%']]]) === 0) {
            (new \ITILFollowup())->add([
                'itemtype' => 'Ticket', 'items_id' => $ticketId, 'content' => self::note($device, count($hits)),
                'is_private' => 1, 'users_id' => 0, 'date' => date('Y-m-d H:i:s'), '_disablenotif' => true,
            ]);
        }
        return $hits === [] ? 'missing' : 'ambiguous';
    }
}
