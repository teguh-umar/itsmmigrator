<?php

require __DIR__ . '/bootstrap.php';
foreach (['Adapter/ClientException', 'Adapter/Dto/ExternalItem', 'Adapter/Dto/ExternalUser', 'Adapter/Dto/ExternalGroup', 'Adapter/Dto/ExternalCategory',
    'Adapter/Dto/ExternalTicket', 'Adapter/Dto/ExternalNote', 'Adapter/Dto/ExternalTask', 'Adapter/Dto/ExternalWorklog', 'Adapter/Dto/ExternalAttachment',
    'Adapter/Dto/TicketChildren', 'Adapter/Dto/Page', 'Adapter/SourceAdapterInterface', 'Adapter/Freshservice/FreshserviceClient', 'Adapter/Freshservice/FreshserviceAdapter'] as $f) {
    require_once ITSM_ROOT . "/src/$f.php";
}

use GlpiPlugin\Itsmmigrator\Adapter\ClientException;
use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceAdapter as A;
use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceClient;

const KEY = 'SECRET-KEY-123';
function fx(string $n): string { return (string) file_get_contents(__DIR__ . "/fixtures/freshservice/$n.json"); }

/** A scripted transport: routes by URL substring, records every call. */
function transport(array $routes, array &$calls): callable
{
    return function (string $url, array $headers, ?string $sink) use ($routes, &$calls): array {
        $calls[] = ['url' => $url, 'headers' => $headers, 'sink' => $sink];
        foreach ($routes as $needle => $resp) {
            if (str_contains($url, $needle)) {
                $resp = is_callable($resp) ? $resp($url) : $resp;
                return $resp + ['headers' => []];
            }
        }
        return ['status' => 404, 'headers' => [], 'body' => '{}'];
    };
}
$noSleep = function (float $s) use (&$slept) { $slept[] = $s; };

// ---- construction and auth
try { new FreshserviceClient('http://x.example.test', KEY); check('http base URL refused', false); }
catch (ClientException $e) { check('http base URL refused', true); }
$calls = []; $slept = [];
$c = new FreshserviceClient('https://acme.example.test', KEY, transport(['/agents' => ['status' => 200, 'body' => fx('agents')]], $calls), $noSleep, 0.0);
$r = $c->get('/agents', ['per_page' => 1]);
check('GET goes to /api/v2', str_starts_with($calls[0]['url'], 'https://acme.example.test/api/v2/agents?per_page=1'));
check('Basic auth is "{key}:X"', in_array('Authorization: Basic ' . base64_encode(KEY . ':X'), $calls[0]['headers'], true));
check('JSON decoded', ($r['json']['agents'][0]['id'] ?? 0) === 201);

// ---- retries
$calls = []; $slept = []; $n = 0;
$c = new FreshserviceClient('https://acme.example.test', KEY, transport(['/x' => function () use (&$n) { return ++$n < 3 ? ['status' => 429, 'headers' => ['Retry-After' => '7'], 'body' => ''] : ['status' => 200, 'body' => '{"ok":1}']; }], $calls), $noSleep, 0.0);
$r = $c->get('/x');
check('429: waits Retry-After and retries', $n === 3 && $slept === [7.0, 7.0], json_encode($slept));
check('Retry-After default is 60', FreshserviceClient::retryAfter([]) === 60.0 && FreshserviceClient::retryAfter(['retry-after' => 'abc']) === 60.0);
$calls = []; $slept = []; $n = 0;
$c = new FreshserviceClient('https://acme.example.test', KEY, transport(['/x' => function () use (&$n) { return ++$n < 4 ? ['status' => 503, 'body' => ''] : ['status' => 200, 'body' => '{"ok":1}']; }], $calls), $noSleep, 0.0);
$c->get('/x');
check('5xx: exponential backoff 1,2,4', $slept === [1.0, 2.0, 4.0], json_encode($slept));
$calls = []; $slept = [];
$c = new FreshserviceClient('https://acme.example.test', KEY, transport(['/x' => ['status' => 429, 'headers' => ['Retry-After' => '1'], 'body' => '']], $calls), $noSleep, 0.0);
try { $c->get('/x'); check('gives up after 5 retries', false); }
catch (ClientException $e) { check('gives up after 5 retries', count($calls) === 6 && $e->reason === 'rate_limit'); }
$c = new FreshserviceClient('https://acme.example.test', KEY, transport(['/x' => ['status' => 401, 'body' => '{}']], $calls), $noSleep, 0.0);
try { $c->get('/x'); check('401 fails at once', false); }
catch (ClientException $e) {
    check('401 fails at once, reason auth', $e->reason === 'auth' && $e->status === 401);
    check('the key is never in an error message', !str_contains($e->getMessage(), KEY));
}
$calls = []; $slept = [];
$c = new FreshserviceClient('https://acme.example.test', KEY, transport(['/a' => ['status' => 200, 'body' => '{}'], '/b' => ['status' => 200, 'body' => '{}']], $calls), $noSleep, 0.4);
$c->get('/a'); $c->get('/b');
check('a minimum gap is kept between requests', count($slept) === 1 && $slept[0] > 0 && $slept[0] <= 0.4);

// ---- attachments: no Authorization to S3
$calls = [];
$c = new FreshserviceClient('https://acme.example.test', KEY, transport(['s3.example.test' => ['status' => 200, 'body' => '']], $calls), $noSleep, 0.0);
$c->download('https://s3.example.test/bucket/9001?sig=abc', sys_get_temp_dir() . '/itsm_dl_test');
check('attachment download carries NO Authorization header', !array_filter($calls[0]['headers'], fn ($h) => stripos($h, 'authorization') === 0));
try { $c->download('http://s3.example.test/x', '/tmp/x'); check('non-https attachment refused', false); } catch (ClientException $e) { check('non-https attachment refused', true); }

// ---- adapter: users (agents then requesters), groups, categories
$calls = [];
$routes = [
    '/api/v2/groups/302'        => ['status' => 200, 'body' => fx('group_302')],
    '/api/v2/groups'            => ['status' => 200, 'body' => fx('groups')],
    '/api/v2/agents'            => ['status' => 200, 'body' => fx('agents')],
    '/api/v2/requesters'        => ['status' => 200, 'body' => fx('requesters')],
    '/api/v2/ticket_form_fields' => ['status' => 200, 'body' => fx('ticket_form_fields')],
    '/api/v2/tickets/1001/conversations' => ['status' => 200, 'body' => fx('conversations_1001')],
    '/api/v2/tickets/1001/tasks' => ['status' => 200, 'body' => fx('tasks_1001')],
    '/api/v2/tickets/1001/time_entries' => ['status' => 200, 'body' => fx('time_entries_1001')],
    '/api/v2/tickets/1001' => ['status' => 200, 'body' => fx('ticket_1001')],
    '/api/v2/tickets' => ['status' => 200, 'body' => fx('tickets_page1')],
];
$ad = new A(new FreshserviceClient('https://acme.example.test', KEY, transport($routes, $calls), $noSleep, 0.0));
$p1 = $ad->fetchPage('users', 1, null);
check('users page 1 = the agents, cursor points at the requesters', $p1->cursor === 'requesters:1' && count($p1->items) === 1 && $p1->hasMore && $p1->items[0]->srcId === 'agent:201' && $p1->items[0]->isTechnician);
$p2 = $ad->fetchPage('users', 2, null, $p1->cursor);
check('users page 2 = the requesters, last', count($p2->items) === 2 && !$p2->hasMore && $p2->items[0]->srcId === 'requester:501');
check('requester keeps secondary e-mails', $p2->items[0]->emails === ['req.uno@example.test', 'uno.alt@example.test']);
check('agent and requester ids never collide', $p1->items[0]->srcId !== 'requester:201');
$cat = $ad->fetchPage('categories', 1, null);
$paths = array_map(fn ($c) => implode(' > ', $c->path), $cat->items);
check('categories: every node of the tree is a path', $paths === ['Hardware', 'Hardware > Laptop', 'Hardware > Desktop', 'Software', 'Network'], json_encode($paths));
check('categories: only one page', !$cat->hasMore && $ad->fetchPage('categories', 2, null)->items === []);

// ---- groups and their members
$calls = [];
$gp = $ad->fetchPage('groups', 1, null);
check('groups: two groups', count($gp->items) === 2 && $gp->items[0]->name === 'Service Desk' && !$gp->hasMore);
check('groups: members from the list answer, as agent:<id>', $gp->items[0]->memberSrcIds === ['agent:201', 'agent:202']);
check('groups: a group without members in the list is fetched once; junk ids dropped', $gp->items[1]->memberSrcIds === ['agent:201', 'agent:203'], json_encode($gp->items[1]->memberSrcIds));
check('groups: exactly one extra request, for the group that needed it', count(array_filter($calls, fn ($c) => str_contains($c['url'], '/groups/302'))) === 1 && count(array_filter($calls, fn ($c) => str_contains($c['url'], '/groups/301'))) === 0);
check('memberRefs: objects with an id accepted, duplicates dropped', A::memberRefs(['members' => [['id' => 5], ['id' => '5'], 6]]) === ['agent:5', 'agent:6'] && A::memberRefs([]) === []);

// ---- tickets
$calls = [];
$pg = $ad->fetchPage('tickets', 1, null);
check('tickets: updated_since is always sent (default 2010)', str_contains($calls[0]['url'], 'updated_since=2010-01-01T00%3A00%3A00Z'));
$since = new DateTimeImmutable('2024-02-01T00:00:00Z');
$ad->fetchPage('tickets', 1, $since);
check('tickets: --since is honoured', str_contains($calls[1]['url'], 'updated_since=2024-02-01T00%3A00%3A00Z'));
$t = $pg->items[0];
check('ticket: id, subject, content', $t->srcId === '1001' && $t->subject === 'Laptop will not boot' && str_contains($t->content, 'Black screen'));
check('ticket: numeric codes become source labels', $t->status === 'Open' && $t->priority === 'High' && $t->type === 'Incident');
check('ticket: requester / assignee / group refs', $t->requesterSrcId === 'requester:501' && $t->assigneeSrcId === 'agent:201' && $t->groupSrcId === '301');
check('ticket: category path drops empty levels', $t->categoryPath === ['Hardware', 'Laptop']);
check('ticket: dates are UTC', $t->createdAt->format('c') === '2024-03-01T01:02:03+00:00' && $t->updatedAt->format('c') === '2024-03-02T04:05:06+00:00');
$t2 = $pg->items[1];
check('ticket: +07:00 date converted to UTC', $t2->createdAt->format('Y-m-d H:i') === '2024-03-02 17:00');
check('ticket: Closed + Service Request + no assignee', $t2->status === 'Closed' && $t2->priority === 'Low' && $t2->type === 'Service Request' && $t2->assigneeSrcId === null && $t2->categoryPath === []);
check('ticket: not linked to another source by itself', $t->originRef === null);
check('tickets: over the Freshservice page cap is a clear error', (function () use ($ad) { try { $ad->fetchPage('tickets', 301, null); return false; } catch (ClientException $e) { return $e->reason === 'limit'; } })());

// ---- children
$ch = $ad->fetchTicketChildren('1001');
check('children: two notes, the second private', count($ch->notes) === 2 && !$ch->notes[0]->isPrivate && $ch->notes[1]->isPrivate);
check('children: note author is the raw id (resolved later)', $ch->notes[0]->authorSrcId === '201');
check('children: one task, done', count($ch->tasks) === 1 && $ch->tasks[0]->state === 'done' && $ch->tasks[0]->assigneeSrcId === 'agent:201');
check('children: worklog 01:30 = 5400 s', count($ch->worklogs) === 1 && $ch->worklogs[0]->seconds === 5400);
check('children: ticket + note attachments, note one linked to its note', count($ch->attachments) === 2 && $ch->attachments[0]->noteSrcId === null && $ch->attachments[1]->noteSrcId === '7001');
check('children: resolution text and resolved date', str_contains((string) $ch->resolution, 'Reimaged') && $ch->resolvedAt?->format('c') === '2024-03-02T03:00:00+00:00' && $ch->closedAt === null);
check('seconds(): hh:mm and hh:mm:ss', A::seconds('00:45') === 2700 && A::seconds('02:00:30') === 7230 && A::seconds('') === 0);
check('every request was a GET to /api/v2 on the configured host', (function () use ($calls) { foreach ($calls as $c) { if (!str_starts_with($c['url'], 'https://acme.example.test/api/v2/')) { return false; } } return true; })());

done();
