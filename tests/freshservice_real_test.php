<?php

// The adapter against SCRUBBED REAL Freshservice answers (recorded 2026-11-18 with tools/fs_record.php).
require __DIR__ . '/bootstrap.php';
foreach (['Adapter/ClientException', 'Adapter/Dto/ExternalItem', 'Adapter/Dto/ExternalUser', 'Adapter/Dto/ExternalGroup', 'Adapter/Dto/ExternalCategory',
    'Adapter/Dto/ExternalTicket', 'Adapter/Dto/ExternalNote', 'Adapter/Dto/ExternalTask', 'Adapter/Dto/ExternalWorklog', 'Adapter/Dto/ExternalAttachment',
    'Adapter/Dto/TicketChildren', 'Adapter/Dto/Page', 'Adapter/SourceAdapterInterface', 'Adapter/Freshservice/FreshserviceClient', 'Adapter/Freshservice/FreshserviceAdapter', 'Mapping'] as $f) {
    require_once ITSM_ROOT . "/src/$f.php";
}

use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceAdapter as A;
use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceClient;
use GlpiPlugin\Itsmmigrator\Mapping;

const DIR = __DIR__ . '/fixtures/freshservice-real';
function rx(string $n): string { return (string) file_get_contents(DIR . "/$n.json"); }
function rj(string $n): array { return json_decode(rx($n), true, 512, JSON_THROW_ON_ERROR); }

$calls = [];
$transport = function (string $url, array $h, ?string $sink) use (&$calls, &$routes): array {
    $calls[] = $url;
    foreach ($routes as $needle => $body) {
        if (str_contains($url, $needle)) {
            return ['status' => 200, 'headers' => str_contains($needle, '/tickets?') || $needle === '/api/v2/groups' ? ['Link' => '<https://x.example.test/next>; rel="next"'] : [], 'body' => $body];
        }
    }
    return ['status' => 404, 'headers' => [], 'body' => '{}'];
};
$tid = (int) rj('ticket_detail_resolved')['ticket']['id'];
$routes = [
    "/api/v2/tickets/$tid/conversations" => rx('conversations_with_attachment'),
    "/api/v2/tickets/$tid/tasks"         => '{"tasks":[]}',
    "/api/v2/tickets/$tid/time_entries"  => '{"time_entries":[]}',
    "/api/v2/tickets/$tid"               => rx('ticket_detail_resolved'),
    '/api/v2/tickets'                    => rx('tickets_page1'),
    '/api/v2/ticket_form_fields'         => rx('ticket_form_fields'),
    '/api/v2/groups'                     => rx('groups'),
    '/api/v2/agents'                     => rx('agents'),
    '/api/v2/requesters'                 => rx('requesters'),
];
$ad = new A(new FreshserviceClient('https://acme.example.test', 'KEY', $transport, fn (float $s) => null, 0.0));

// ---- users
$p1 = $ad->fetchPage('users', 1, null);
check('agents: 3 users, technician flag, e-mail from `email`', count($p1->items) === 3 && $p1->items[0]->isTechnician && str_ends_with($p1->items[0]->emails[0] ?? '', '@example.test'));
check('agents: ids are prefixed agent:<id>', str_starts_with($p1->items[0]->srcId, 'agent:'));
$p2 = $ad->fetchPage('users', 2, null, $p1->cursor);
check('requesters: 3 users from primary_email / secondary_emails, never technicians', count($p2->items) === 3 && !$p2->items[0]->isTechnician && str_starts_with($p2->items[0]->srcId, 'requester:'));
$noMail = array_filter($p2->items, fn ($u) => $u->emails === []);
check('requesters: a requester with a null primary_email has NO e-mail (not an empty-string e-mail)', count($noMail) >= 1 && array_filter($p2->items, fn ($u) => in_array('', $u->emails, true)) === []);
check('users: the real answer marks more pages with a Link header or a full page; the agents cursor leads to the requesters', $p1->cursor !== null && str_starts_with((string) $p1->cursor, 'agents:') || $p1->cursor === 'requesters:1');

// ---- groups: members come in the list answer under `members`, no per-group request is needed
$calls = [];
$gp = $ad->fetchPage('groups', 1, null);
check('groups: 5 groups, names, real list answer has `members`', count($gp->items) === 5 && $gp->items[0]->name !== '');
check('groups: members read as agent:<id>', $gp->items[0]->memberSrcIds !== [] && str_starts_with($gp->items[0]->memberSrcIds[0], 'agent:'));
check('groups: no per-group GET when the list carries the members', count(array_filter($calls, fn ($u) => preg_match('#/groups/\d+#', $u))) === 0, json_encode($calls));
check('groups: more pages signalled by the Link header', $gp->hasMore === true);

// ---- categories: real choices are {id, display_id, value, nested_options}
$cat = $ad->fetchPage('categories', 1, null);
$paths = array_map(fn ($c) => $c->path, $cat->items);
check('categories: a tree of paths up to 3 levels', count($paths) >= 10 && max(array_map('count', $paths)) === 3 && min(array_map('count', $paths)) === 1, json_encode(array_map('count', $paths)));
check('categories: every level is a word, never an id or an empty string', array_filter($paths, fn ($p) => array_filter($p, fn ($w) => !is_string($w) || trim($w) === '')) === []);
check('categories: the srcId is the joined path', $cat->items[0]->srcId === implode(' > ', $cat->items[0]->path));

// ---- tickets
$calls = [];
$tp = $ad->fetchPage('tickets', 1, null);
check('tickets: 8 recorded, updated_since always sent', count($tp->items) === 8 && str_contains($calls[0] ?? '', 'updated_since='));
check('tickets: the account\'s status wording was read once from the ticket form', count(array_filter($calls, fn ($u) => str_contains($u, '/ticket_form_fields'))) === 1);
$statuses = array_unique(array_map(fn ($t) => $t->status, $tp->items));
check('tickets: codes 2..5 become Open/Pending/Resolved/Closed', in_array('Open', $statuses, true) && in_array('Resolved', $statuses, true));
check('tickets: the account\'s own statuses keep their real label (In Progress, Waiting on Approval), not "Open"', in_array('In Progress', $statuses, true) && in_array('Waiting on Approval', $statuses, true), json_encode($statuses));
check('tickets: priority is Low/Medium/High/Urgent', array_diff(array_unique(array_map(fn ($t) => $t->priority, $tp->items)), ['Low', 'Medium', 'High', 'Urgent']) === []);
check('tickets: type stays the source word (Incident / Service Request)', array_diff(array_unique(array_map(fn ($t) => $t->type, $tp->items)), ['Incident', 'Service Request']) === []);
$t0 = $tp->items[0];
check('ticket: requester, assignee and group refs', str_starts_with((string) $t0->requesterSrcId, 'requester:') && ($t0->assigneeSrcId === null || str_starts_with($t0->assigneeSrcId, 'agent:')) && ($t0->groupSrcId === null || ctype_digit($t0->groupSrcId)));
check('ticket: dates are UTC DateTimeImmutable', $t0->createdAt instanceof DateTimeImmutable && $t0->createdAt->getTimezone()->getName() === 'UTC' && $t0->updatedAt >= $t0->createdAt);
check('ticket: description is HTML, subject is not empty', str_contains($t0->content, '<div>') && $t0->subject !== '');
check('ticket: the category path is made of the same words as the form\'s choices', array_filter($t0->categoryPath, fn ($w) => !preg_match('/^Choice \d+$/', $w)) === []);

// ---- mapping of the real statuses
$mapped = array_map(fn ($t) => Mapping::status($t->status, $t->assigneeSrcId !== null), $tp->items);
check('mapping: "In Progress" is known (Assigned/New), "Waiting on Approval" is the GLPI status Approval (10), known', Mapping::status('In Progress', true) === ['value' => 2, 'known' => true] && Mapping::status('Waiting on Approval', true) === ['value' => 10, 'known' => true]);
check('mapping: a custom wording is guessed by its words', Mapping::guessStatus('on hold - vendor', true) === 4 && Mapping::guessStatus('completed', true) === 5 && Mapping::guessStatus('rejected', true) === 6 && Mapping::guessStatus('something else', false) === 1);
check('mapping: the source\'s own map beats the guess', Mapping::status('Waiting on Approval', true, ['waiting on approval' => 2]) === ['value' => 2, 'known' => true]);

// ---- children: the real detail has resolution_notes(_html), stats, attachments; conversations have meta
$ch = $ad->fetchTicketChildren((string) $tid);
check('children: real conversations parsed (body, private flag, author id)', count($ch->notes) >= 1 && $ch->notes[0]->body !== '' && is_bool($ch->notes[0]->isPrivate) && $ch->notes[0]->authorSrcId !== null);
check('children: an attachment on a conversation is linked to its note and keeps name, size, type, URL', count(array_filter($ch->attachments, fn ($a) => $a->noteSrcId !== null && $a->size > 0 && $a->contentType !== null && str_starts_with($a->url, 'https://'))) >= 1);
check('children: the resolved date comes from `stats` (closed_at stays null for a Resolved ticket)', $ch->resolvedAt instanceof DateTimeImmutable && $ch->closedAt === null);
check('children: no tasks and no time entries on this ticket', $ch->tasks === [] && $ch->worklogs === []);
$d = rj('ticket_detail_resolved')['ticket'];
check('detail: the real keys are resolution_notes / resolution_notes_html and stats', array_key_exists('resolution_notes_html', $d) && isset($d['stats']['resolved_at']));
check('detail: "resolution" does NOT exist on a real ticket (the first guess was wrong)', !array_key_exists('resolution', $d));
$pr = rj('conversations_private');
check('conversations: `private` and `meta.has_more` exist on the real answer', $pr['conversations'][0]['private'] === true && array_key_exists('has_more', $pr['meta']));
check('every request was a GET under /api/v2 on the configured host', array_filter($calls, fn ($u) => !str_starts_with($u, 'https://acme.example.test/api/v2/')) === []);

// ---- the recorded fixtures contain nothing real
$bad = [];
foreach (glob(DIR . '/*.json') as $file) {
    $t = (string) file_get_contents($file);
    if (preg_match('/\b1[0-9]{10,}\b/', $t)) { $bad[] = basename($file) . ': a long id'; }
    foreach ([ '/[A-Za-z0-9._%+-]+@(?!example\.test)[A-Za-z0-9.-]+/', '#https?://(?!example\.test/)[^"\s]+#' ] as $re) {
        if (preg_match($re, $t)) { $bad[] = basename($file) . ': a real e-mail or URL'; }
    }
    $j = json_decode($t, true);
    array_walk_recursive($j, function ($v, $k) use (&$bad, $file) {
        // Free text must be a placeholder. Empty strings and a form field's identifier (a lower-case slug under `name`) are fine.
        if (is_string($v) && $v !== '' && in_array($k, ['subject', 'description', 'description_text', 'body', 'body_text', 'first_name', 'last_name', 'job_title', 'name'], true)
            && !($k === 'name' && preg_match('/^[a-z][a-z_0-9]*$/', $v))
            && !preg_match('/^(Text|Name|First name|Last name|Job title|Field|Choice|Label) \d+$|^<div>Text \d+<\/div>$/', $v)) {
            $bad[] = basename($file) . ": free text under '$k'";
        }
    });
}
check('fixtures: no real id, e-mail, URL or free text (' . count(glob(DIR . '/*.json')) . ' files)', $bad === [], json_encode(array_slice($bad, 0, 5)));

done();
