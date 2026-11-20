<?php

// Ticket approvals: the adapter's reading of a Freshservice approval and the wiring of the opt-in. Offline, synthetic.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/glpi_stubs.php';
foreach (['Adapter/ClientException', 'Adapter/Dto/ExternalItem', 'Adapter/Dto/ExternalUser', 'Adapter/Dto/ExternalGroup', 'Adapter/Dto/ExternalCategory',
    'Adapter/Dto/ExternalTicket', 'Adapter/Dto/ExternalNote', 'Adapter/Dto/ExternalTask', 'Adapter/Dto/ExternalWorklog', 'Adapter/Dto/ExternalAttachment',
    'Adapter/Dto/ExternalApproval', 'Adapter/Dto/TicketChildren', 'Adapter/Dto/Page', 'Adapter/SourceAdapterInterface',
    'Adapter/Freshservice/FreshserviceClient', 'Adapter/Freshservice/FreshserviceAdapter'] as $f) {
    require_once ITSM_ROOT . "/src/$f.php";
}
load_src('RightDefinition', 'DateRange', 'Source');

use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceAdapter as A;
use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceClient;
use GlpiPlugin\Itsmmigrator\Source;

$a = A::approval(['id' => 7, 'approval_status' => 'Approved', 'approver_id' => 501, 'user_id' => 201, 'latest_remark' => 'Fine &amp; ok', 'level' => 2,
    'created_at' => '2026-02-01T01:00:00Z', 'updated_at' => '2026-02-02T03:00:00Z'], '1001');
check('approval: approved, level 2, approver and requester refs', $a->status === 'approved' && $a->level === 2 && $a->approverSrcId === '501' && $a->requesterSrcId === '201' && $a->srcId === '7');
check('approval: remark decoded, decision date = last update', $a->remark === 'Fine & ok' && $a->decidedAt->format('c') === '2026-02-02T03:00:00+00:00' && $a->createdAt->format('c') === '2026-02-01T01:00:00+00:00');
$r = A::approval(['id' => 8, 'approval_status_name' => 'Rejected', 'approver_id' => 9, 'created_at' => '2026-02-01T01:00:00Z', 'updated_at' => '2026-02-01T02:00:00Z'], '1');
check('approval: rejected; no level means level 1', $r->status === 'rejected' && $r->level === 1);
$q = A::approval(['id' => 9, 'approval_status' => 'Requested', 'approver_id' => 9, 'level' => 3, 'updated_at' => '2026-02-01T02:00:00Z'], '1');
check('approval: requested has no decision date', $q->status === 'requested' && $q->decidedAt === null && $q->level === 3);
check('approval: an unknown status is never read as a decision', A::approval(['id' => 1, 'approval_status' => 'Whatever'], '1')->status === 'requested' && A::approval(['id' => 1], '1')->status === 'requested');
check('approval: level is at least 1', A::approval(['id' => 1, 'level' => 0], '1')->level === 1);

// the extra request is made only when asked for
$calls = [];
$transport = function (string $url, array $h, ?string $sink) use (&$calls): array {
    $calls[] = $url;
    if (str_contains($url, '/approvals')) { return ['status' => 200, 'headers' => [], 'body' => json_encode(['approvals' => [['id' => 5, 'approval_status' => 'Approved', 'approver_id' => 3, 'level' => 1]]])]; }
    if (str_contains($url, '/tickets/1001')) { return ['status' => 200, 'headers' => [], 'body' => '{"ticket":{"id":1001}}']; }
    return ['status' => 200, 'headers' => [], 'body' => '{"conversations":[],"tasks":[],"time_entries":[]}'];
};
$ad = new A(new FreshserviceClient('https://acme.example.test', 'KEY', $transport, fn (float $s) => null, 0.0));
$off = $ad->fetchTicketChildren('1001');
$callsOff = array_filter($calls, fn ($u) => str_contains($u, '/approvals'));
$on = $ad->fetchTicketChildren('1001', true);
check('children without approvals: no approvals request, empty list', $callsOff === [] && $off->approvals === []);
check('children with approvals: one request, one approval', count(array_filter($calls, fn ($u) => str_contains($u, '/approvals'))) === 1 && count($on->approvals) === 1 && $on->approvals[0]->status === 'approved');

// a ticket that never had an approval: Freshservice answers 404 on /approvals; that is "none", never a failed ticket
$t404 = function (string $url, array $h, ?string $sink): array {
    if (str_contains($url, '/approvals')) { return ['status' => 404, 'headers' => [], 'body' => '{"code":"resource_not_found"}']; }
    if (preg_match('#/tickets/1002(\?|$)#', $url)) { return ['status' => 200, 'headers' => [], 'body' => '{"ticket":{"id":1002}}']; }
    return ['status' => 200, 'headers' => [], 'body' => '{"conversations":[],"tasks":[],"time_entries":[]}'];
};
$ad404 = new A(new FreshserviceClient('https://acme.example.test', 'KEY', $t404, fn (float $s) => null, 0.0));
$none = $ad404->fetchTicketChildren('1002', true);
check('approvals 404 (a ticket with no approval) gives an empty list, not an error', $none->approvals === []);
$t500 = function (string $url, array $h, ?string $sink): array {
    if (str_contains($url, '/approvals')) { return ['status' => 403, 'headers' => [], 'body' => '{}']; }
    if (preg_match('#/tickets/1003(\?|$)#', $url)) { return ['status' => 200, 'headers' => [], 'body' => '{"ticket":{"id":1003}}']; }
    return ['status' => 200, 'headers' => [], 'body' => '{"conversations":[],"tasks":[],"time_entries":[]}'];
};
try { (new A(new FreshserviceClient('https://acme.example.test', 'KEY', $t500, fn (float $s) => null, 0.0)))->fetchTicketChildren('1003', true); check('any other approvals error (403) is still an error', false); } catch (\Throwable $e) { check('any other approvals error (403) is still an error', true); }

// the option and the flag
$o = json_decode((string) Source::prepare(['import_approvals' => '1'], static fn ($s) => $s, ['options' => json_encode(['import_since' => '2026-01-01'])])['options'], true);
check('source option import_approvals is kept next to the others', $o === ['import_since' => '2026-01-01', 'import_approvals' => true]);
check('source option: unticking removes it', Source::prepare(['import_approvals' => '0'], static fn ($s) => $s, ['options' => json_encode(['import_approvals' => true])])['options'] === null);

$tw = (string) file_get_contents(ITSM_ROOT . '/src/Writer/TicketWriter.php');
check('writer: approvals are opt-in (option or flag)', str_contains($tw, "flag('approvals')") && str_contains($tw, "option('import_approvals')") && str_contains($tw, 'fetchTicketChildren($t->srcId, self::wantsApprovals($ctx))'));
check('writer: an unknown approver never gets the fallback user', str_contains($tw, 'resolve($ap->source, $ap->approverSrcId, $ctx, false)'));
check('writer: one approval step per level', str_contains($tw, "'Level ' . \$level") && str_contains($tw, '_validationsteps_id'));
$rc = (string) file_get_contents(ITSM_ROOT . '/src/Console/RunCommand.php');
check('CLI has --approvals, and an absent flag does not override the source option', str_contains($rc, "addOption('approvals'") && str_contains($rc, "getOption('approvals') ? true : null"));
check('web run whitelists approvals', str_contains((string) file_get_contents(ITSM_ROOT . '/src/WebRun.php'), "'approvals' => 'bool'") && str_contains((string) file_get_contents(ITSM_ROOT . '/ajax/run.php'), "'approvals'"));

done();
