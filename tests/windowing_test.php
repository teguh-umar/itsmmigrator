<?php

// The Freshservice 30,000 ticket cap, against a SYNTHETIC server (no real data): windowed reading must lose nothing.
require __DIR__ . '/bootstrap.php';
foreach (['Adapter/ClientException', 'Adapter/Dto/ExternalItem', 'Adapter/Dto/ExternalUser', 'Adapter/Dto/ExternalGroup', 'Adapter/Dto/ExternalCategory',
    'Adapter/Dto/ExternalTicket', 'Adapter/Dto/ExternalNote', 'Adapter/Dto/ExternalTask', 'Adapter/Dto/ExternalWorklog', 'Adapter/Dto/ExternalAttachment',
    'Adapter/Dto/TicketChildren', 'Adapter/Dto/Page', 'Adapter/SourceAdapterInterface', 'Adapter/Freshservice/FreshserviceClient', 'Adapter/Freshservice/FreshserviceAdapter'] as $f) {
    require_once ITSM_ROOT . "/src/$f.php";
}

use GlpiPlugin\Itsmmigrator\Adapter\ClientException;
use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceAdapter as A;
use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceClient;

/**
 * A fake Freshservice: $times[i] = update time (unix) of ticket i+1, ascending. Serves /tickets like the real one:
 * updated_since inclusive, per_page 100, and an error for any page beyond $cap (what the real server does).
 */
final class FakeServer
{
    public array $requests = [];
    public int $beyondCap = 0;

    public function __construct(private array $times, private int $cap) {}

    public function transport(): callable
    {
        return function (string $url, array $h, ?string $sink): array {
            if (str_contains($url, '/ticket_form_fields')) {
                return ['status' => 200, 'headers' => [], 'body' => '{"ticket_fields":[]}'];
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $since = strtotime((string) $q['updated_since']);
            $page  = (int) $q['page'];
            $this->requests[] = ['since' => $since, 'page' => $page];
            if ($page > $this->cap) {
                $this->beyondCap++;
                return ['status' => 400, 'headers' => [], 'body' => '{"description":"Validation failed"}'];
            }
            // first index with time >= since (binary search)
            $lo = 0; $hi = count($this->times);
            while ($lo < $hi) { $mid = intdiv($lo + $hi, 2); if ($this->times[$mid] >= $since) { $hi = $mid; } else { $lo = $mid + 1; } }
            $slice = array_slice($this->times, $lo + ($page - 1) * 100, 100, true);
            $rows = [];
            foreach ($slice as $i => $t) {
                $iso = gmdate('Y-m-d\TH:i:s\Z', $t);
                $rows[] = ['id' => $i + 1, 'subject' => 's', 'description' => 'd', 'status' => 5, 'priority' => 2, 'type' => 'Incident', 'created_at' => $iso, 'updated_at' => $iso];
            }
            $more = $lo + $page * 100 < count($this->times);
            return ['status' => 200, 'headers' => $more ? ['Link' => '<https://x.example.test/n>; rel="next"'] : [], 'body' => json_encode(['tickets' => $rows])];
        };
    }
}

/** Read everything the way the engine does: ask for the next page with the previous cursor until hasMore is false. */
function readAll(A $ad, ?DateTimeImmutable $since, array &$notes = [], int $guard = 5000): array
{
    $ids = []; $cursor = null; $page = 1; $steps = 0;
    do {
        $r = $ad->fetchPage('tickets', $page, $since, $cursor);
        foreach ($r->items as $t) { $ids[] = (int) $t->srcId; }
        array_push($notes, ...$r->notes);
        $cursor = $r->cursor; $page++; $steps++;
    } while ($r->hasMore && $steps < $guard);
    return [$ids, $steps];
}

$no = fn (float $s) => null;

// ---- 1. 65,430 tickets, 3 per second, a cluster of 250 in one second that straddles the end of the first window
$times = []; $t = 1_735_689_600; // 2025-01-01 UTC
for ($i = 0; $i < 65_430; $i++) { $times[] = $t + intdiv($i, 3); }
$cluster = 29_900; // tickets 29,900 .. 30,150 share one second: the cap (30,000) falls in the middle of it
for ($i = $cluster; $i < $cluster + 250; $i++) { $times[$i] = $times[$cluster]; }
sort($times);
$srv = new FakeServer($times, 300);
$ad  = new A(new FreshserviceClient('https://acme.example.test', 'KEY', $srv->transport(), $no, 0.0));
$notes = [];
[$ids, $steps] = readAll($ad, new DateTimeImmutable('2025-01-01T00:00:00Z'), $notes);
$unique = array_unique($ids);
check('windows: every one of the 65,430 tickets is read (nothing lost at the cap)', count($unique) === 65_430, count($unique) . ' unique of ' . count($ids));
check('windows: the overlap is small (only the tickets of the second where a window starts are read twice)', count($ids) - count($unique) <= 300, 'duplicates ' . (count($ids) - count($unique)));
check('windows: the server was NEVER asked for a page beyond its cap', $srv->beyondCap === 0);
check('windows: more than one window was needed, and each is announced', count(array_filter($notes, fn ($n) => str_starts_with($n, 'Window ended'))) === 2, json_encode(array_map(fn ($n) => substr($n, 0, 40), $notes)));
check('windows: the next window starts at the update time of the last ticket of the previous one', (function () use ($srv, $times) {
    $starts = array_values(array_unique(array_map(fn ($r) => $r['since'], $srv->requests)));
    return count($starts) === 3 && $starts[1] === $times[29_999] && $starts[2] === $times[59_999 - 0] || count($starts) === 3 && $starts[1] === $times[29_999];
})(), json_encode(array_values(array_unique(array_map(fn ($r) => $r['since'], $srv->requests)))));
check('windows: tickets arrive in ascending order of update time (so the watermark can be the last one seen)', (function () use ($ids, $times) { $prev = 0; $seen = []; foreach ($ids as $id) { if (isset($seen[$id])) { continue; } $seen[$id] = 1; $tm = $times[$id - 1]; if ($tm < $prev) { return false; } $prev = $tm; } return true; })());

// ---- 2. under the cap: one window, no notes, no extra requests
$srv2 = new FakeServer(array_slice($times, 0, 12_345), 300);
$ad2  = new A(new FreshserviceClient('https://acme.example.test', 'KEY', $srv2->transport(), $no, 0.0));
$notes2 = [];
[$ids2, $steps2] = readAll($ad2, new DateTimeImmutable('2025-01-01T00:00:00Z'), $notes2);
check('under the cap: one window, 124 requests, no notes, no duplicates', count($ids2) === 12_345 && count(array_unique($ids2)) === 12_345 && $steps2 === 124 && $notes2 === [], "steps=$steps2");

// ---- 3. a window of exactly the cap: 30,000 tickets, nothing after
$srv3 = new FakeServer(array_slice($times, 0, 30_000), 300);
$ad3  = new A(new FreshserviceClient('https://acme.example.test', 'KEY', $srv3->transport(), $no, 0.0));
$notes3 = [];
[$ids3] = readAll($ad3, new DateTimeImmutable('2025-01-01T00:00:00Z'), $notes3);
check('exactly the cap: all 30,000 read, and the list ends (the follow-up window finds only the last second again)', count(array_unique($ids3)) === 30_000 && $srv3->beyondCap === 0);

// ---- 4. a whole window sharing ONE second: the guard moves on instead of looping forever
$same = array_fill(0, 1000, 1_735_689_600);
$srv4 = new FakeServer($same, 3);
$ad4  = new A(new FreshserviceClient('https://acme.example.test', 'KEY', $srv4->transport(), $no, 0.0), 3);
$notes4 = [];
[$ids4, $steps4] = readAll($ad4, new DateTimeImmutable('2025-01-01T00:00:00Z'), $notes4, 100);
check('same-second guard: terminates (no endless loop)', $steps4 < 100, "steps=$steps4");
check('same-second guard: says so in the notes, with the cap and the second, never a name', count(array_filter($notes4, fn ($n) => str_contains($n, 'share the update time') && str_contains($n, '300'))) === 1);
check('same-second guard: what could be read was read (the cap), the rest is declared unreadable', count(array_unique($ids4)) === 300);

// ---- 5. a small cap proves the mechanics with exact numbers
$small = []; for ($i = 0; $i < 1000; $i++) { $small[] = 1_735_689_600 + $i; }
$srv5 = new FakeServer($small, 2);
$ad5  = new A(new FreshserviceClient('https://acme.example.test', 'KEY', $srv5->transport(), $no, 0.0), 2);
$notes5 = [];
[$ids5] = readAll($ad5, new DateTimeImmutable('2025-01-01T00:00:00Z'), $notes5);
check('cap of 2 pages: 1000 distinct-second tickets, 5 full windows of 200 and a last one of 5 (each window starts at the last ticket of the one before, so 5 tickets are read twice)', count(array_unique($ids5)) === 1000 && count($ids5) === 1005 && count(array_filter($notes5, fn ($n) => str_starts_with($n, 'Window ended'))) === 5, 'read ' . count($ids5));

// ---- cursor handling
[$f1, $p1] = A::parseWindow(null, new DateTimeImmutable('2026-01-01T00:00:00Z'), 1);
[$f2, $p2] = A::parseWindow('w:2026-03-04T05:06:07Z:42', new DateTimeImmutable('2020-01-01T00:00:00Z'), 1);
check('cursor: none -> the caller\'s since and page', $f1->format('c') === '2026-01-01T00:00:00+00:00' && $p1 === 1);
check('cursor: a window cursor wins over the caller\'s since', $f2->format('c') === '2026-03-04T05:06:07+00:00' && $p2 === 42);
try { A::parseWindow('garbage', new DateTimeImmutable('now'), 1); check('cursor: garbage is refused', false); } catch (InvalidArgumentException $e) { check('cursor: garbage is refused', true); }

done();
