<?php

// A ticket whose requester is not a GLPI user keeps who asked in its first line. Offline, synthetic.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/glpi_stubs.php';
foreach (['Adapter/ClientException', 'Adapter/Dto/ExternalItem', 'Adapter/Dto/ExternalUser', 'Adapter/Dto/ExternalGroup', 'Adapter/Dto/ExternalCategory',
    'Adapter/Dto/ExternalTicket', 'Adapter/Dto/ExternalNote', 'Adapter/Dto/ExternalTask', 'Adapter/Dto/ExternalWorklog', 'Adapter/Dto/ExternalAttachment',
    'Adapter/Dto/ExternalApproval', 'Adapter/Dto/TicketChildren', 'Adapter/Dto/Page', 'Adapter/SourceAdapterInterface',
    'Adapter/Freshservice/FreshserviceClient', 'Adapter/Freshservice/FreshserviceAdapter'] as $f) {
    require_once ITSM_ROOT . "/src/$f.php";
}

use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceAdapter as A;
use GlpiPlugin\Itsmmigrator\Adapter\Freshservice\FreshserviceClient;

$calls = [];
$transport = function (string $url, array $h, ?string $sink) use (&$calls): array {
    $calls[] = $url;
    if (preg_match('#/requesters/501$#', $url)) { return ['status' => 200, 'headers' => [], 'body' => json_encode(['requester' => ['id' => 501, 'first_name' => 'Rina &amp; Co', 'last_name' => 'Uno', 'primary_email' => 'rina@example.test']])]; }
    if (preg_match('#/agents/201$#', $url)) { return ['status' => 200, 'headers' => [], 'body' => json_encode(['agent' => ['id' => 201, 'first_name' => 'Agus', 'last_name' => 'Dua', 'email' => 'agus@example.test']])]; }
    return ['status' => 404, 'headers' => [], 'body' => '{}'];
};
$ad = new A(new FreshserviceClient('https://acme.example.test', 'KEY', $transport, fn (float $s) => null, 0.0));
check('requester: name (decoded) and e-mail', $ad->describeUser('requester:501') === ['name' => 'Rina & Co Uno', 'email' => 'rina@example.test']);
check('a bare id is a requester', $ad->describeUser('501') !== null && $ad->describeUser('agent:201') === ['name' => 'Agus Dua', 'email' => 'agus@example.test']);
$n = count($calls);
$ad->describeUser('requester:501'); $ad->describeUser('agent:201');
check('each user is asked for once (remembered)', count($calls) === $n);
check('an unknown user, or a rubbish id, gives null and never throws', $ad->describeUser('requester:999') === null && $ad->describeUser('requester:../x') === null);

// the line itself (the writer class needs GLPI; its pure helper is read from source)
$src = (string) file_get_contents(ITSM_ROOT . '/src/Writer/TicketWriter.php');
check('writer adds the line only for an unmatched requester, before the description, whatever the fallback user', str_contains($src, 'UserRef::resolve($t->source, $t->requesterSrcId, $ctx, false) === 0') && str_contains($src, 'self::requesterLine($this->adapterRef?->describeUser($t->requesterSrcId)) . $content'));
check('the line escapes the name and says when the source could not be read', str_contains($src, 'htmlspecialchars($label, ENT_QUOTES)') && str_contains($src, 'unknown, not readable'));

done();
