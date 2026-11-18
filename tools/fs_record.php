<?php
// Records REAL Freshservice responses for the offline fixtures, SCRUBBED before anything is written.
// DEV TOOL, not shipped. GET only, a handful of requests, a pause between them.
//
//   php -c <php.ini> tools/fs_record.php <sources.id> <output-dir> [path-to-glpi-root]
//
// What it guarantees (and tests/scrub_test.php pins):
//  - the API key is read from the encrypted source row inside this process; it is never printed or written;
//  - raw responses live only in memory: the scrubber replaces every name, e-mail, subject, body, note, URL, host,
//    phone number and free text by a placeholder BEFORE a file is written, and keeps structure, types, enum values
//    and the relations between ids (ids are renumbered consistently);
//  - the console shows only status codes, counts and file names.
// Read the scrubbed files, never the raw answers.

require_once dirname(__DIR__) . '/src/Adapter/ClientException.php';
require_once dirname(__DIR__) . '/src/Adapter/Freshservice/FreshserviceClient.php';
require_once __DIR__ . '/lib_scrub.php';

use GlpiPlugin\Itsmmigrator\Adapter\ClientException;
use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceClient;

$sid = (int) ($argv[1] ?? 0);
$out = rtrim((string) ($argv[2] ?? ''), '/\\');
$root = $argv[3] ?? 'C:/glpi-staging/app13/glpi';
if ($sid <= 0 || $out === '') {
    fwrite(STDERR, "usage: fs_record.php <sources.id> <output-dir> [glpi-root]\n");
    exit(2);
}
if (!is_dir($out) && !mkdir($out, 0777, true)) {
    fwrite(STDERR, "cannot create output dir\n");
    exit(2);
}

chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('staging');
$kernel->boot();
global $DB;

$row = $DB->request(['FROM' => 'glpi_plugin_itsmmigrator_sources', 'WHERE' => ['id' => $sid, 'adapter' => 'freshservice']])->current();
if ($row === null) {
    fwrite(STDERR, "no such freshservice source\n");
    exit(2);
}
$key = (string) (new GLPIKey())->decrypt((string) $row['api_key']);
if ($key === '') {
    fwrite(STDERR, "no key stored for this source\n");
    exit(2);
}
$client = new FreshserviceClient((string) $row['base_url'], $key, null, null, 1.0, ($row['ca_bundle_path'] ?? '') !== '' ? $row['ca_bundle_path'] : null);
unset($key);

$scrub = new FsScrubber();
$report = [];

/** One GET, scrubbed, written; returns the decoded RAW json for the caller's own navigation (never printed). */
$record = function (string $file, string $endpoint, array $query, string $kind) use ($client, $scrub, $out, &$report): ?array {
    try {
        $r = $client->get($endpoint, $query);
    } catch (ClientException $e) {
        $report[] = sprintf('%-34s FAILED  %s (HTTP %d)', $file, $e->reason, $e->status);
        return null;
    }
    $clean = $scrub->scrub($r['json'], $kind);
    $clean['_recorded'] = ['endpoint_shape' => $endpoint === '' ? '/' : preg_replace('#/\d+#', '/{id}', $endpoint), 'query_keys' => array_keys($query), 'link_next' => $r['link_next']];
    file_put_contents($out . DIRECTORY_SEPARATOR . $file, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $report[] = sprintf('%-34s ok      %s', $file, FsScrubber::shape($clean));
    return $r['json'];
};

$agents     = $record('agents.json', '/agents', ['per_page' => 3], 'user');
$requesters = $record('requesters.json', '/requesters', ['per_page' => 3], 'user');
$groups     = $record('groups.json', '/groups', ['per_page' => 5], 'group');
if (isset($groups['groups'][0]['id'])) {
    $record('group_detail.json', '/groups/' . (int) $groups['groups'][0]['id'], [], 'group');
}
$record('ticket_form_fields.json', '/ticket_form_fields', [], 'form');

// Tickets: the list is always sent with updated_since. Recent ones, so they have conversations.
$tickets = $record('tickets_page1.json', '/tickets', ['updated_since' => '2023-01-01T00:00:00Z', 'per_page' => 25, 'order_by' => 'updated_at', 'order_type' => 'desc'], 'ticket');

// Look through the recent tickets for one of each interesting kind, with a hard cap on requests.
$want = ['conversations_with_attachment' => false, 'tasks_nonempty' => false, 'time_entries_nonempty' => false, 'ticket_detail_resolved' => false, 'ticket_detail_attachment' => false, 'conversations_private' => false];
$first = null;
$budget = 100;
foreach ($tickets['tickets'] ?? [] as $t) {
    if ($budget < 6 || !in_array(false, $want, true)) {
        break;
    }
    $id = (int) $t['id'];
    $first ??= $id;
    $budget -= 3;
    $conv = $client->get('/tickets/' . $id . '/conversations', ['per_page' => 30])['json']['conversations'] ?? [];
    foreach ($conv as $cv) {
        if (!$want['conversations_with_attachment'] && !empty($cv['attachments'])) {
            $want['conversations_with_attachment'] = true;
            $record('conversations_with_attachment.json', '/tickets/' . $id . '/conversations', ['per_page' => 30], 'note');
        }
        if (!$want['conversations_private'] && !empty($cv['private'])) {
            $want['conversations_private'] = true;
            $record('conversations_private.json', '/tickets/' . $id . '/conversations', ['per_page' => 30], 'note');
        }
    }
    $tasks = $client->get('/tickets/' . $id . '/tasks', ['per_page' => 30])['json']['tasks'] ?? [];
    if (!$want['tasks_nonempty'] && $tasks !== []) {
        $want['tasks_nonempty'] = true;
        $record('tasks_nonempty.json', '/tickets/' . $id . '/tasks', ['per_page' => 30], 'task');
    }
    $te = $client->get('/tickets/' . $id . '/time_entries', ['per_page' => 30])['json']['time_entries'] ?? [];
    if (!$want['time_entries_nonempty'] && $te !== []) {
        $want['time_entries_nonempty'] = true;
        $record('time_entries_nonempty.json', '/tickets/' . $id . '/time_entries', ['per_page' => 30], 'task');
    }
    if ((!$want['ticket_detail_resolved'] && in_array((int) $t['status'], [4, 5], true)) || !$want['ticket_detail_attachment']) {
        $budget -= 1;
        $d = $client->get('/tickets/' . $id, ['include' => 'stats'])['json']['ticket'] ?? [];
        if (!$want['ticket_detail_resolved'] && in_array((int) $t['status'], [4, 5], true)) {
            $want['ticket_detail_resolved'] = true;
            $record('ticket_detail_resolved.json', '/tickets/' . $id, ['include' => 'stats'], 'ticket');
        }
        if (!$want['ticket_detail_attachment'] && !empty($d['attachments'])) {
            $want['ticket_detail_attachment'] = true;
            $record('ticket_detail_attachment.json', '/tickets/' . $id, ['include' => 'stats'], 'ticket');
        }
    }
}
if ($first !== null) {
    $record('ticket_detail.json', '/tickets/' . $first, ['include' => 'stats'], 'ticket');
    $record('conversations.json', '/tickets/' . $first . '/conversations', ['per_page' => 5], 'note');
}
foreach ($want as $k => $found) {
    $report[] = sprintf('%-34s %s', $k, $found ? 'found' : 'not found in the recent tickets');
}
echo implode("\n", $report) . "\n";
echo "written to the output dir; ids renumbered, text replaced. The raw answers were never stored.\n";
