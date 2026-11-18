<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/glpi_stubs.php';
foreach (['RightDefinition', 'MapRepository', 'NeedsReview', 'RunView', 'WebRun', 'Tab/ExternalReferenceTab'] as $f) {
    require_once ITSM_ROOT . "/src/$f.php";
}

use GlpiPlugin\Itsmmigrator\NeedsReview;
use GlpiPlugin\Itsmmigrator\RunView;
use GlpiPlugin\Itsmmigrator\Tab\ExternalReferenceTab as Tab;
use GlpiPlugin\Itsmmigrator\WebRun;

// ---- Needs review
check('candidates parsed, sorted, deduplicated', NeedsReview::candidates('candidates: 9,7,7') === [7, 9]);
check('candidates: nothing in, nothing out', NeedsReview::candidates(null) === [] && NeedsReview::candidates('boom') === [] && NeedsReview::candidates('candidates: 0') === []);

// ---- deep links come from the source base_url, https only
check('Freshservice link', Tab::deepLink('freshservice', 'https://acme.example/', 'ticket', '42') === 'https://acme.example/a/tickets/42');
check('SDP link', Tab::deepLink('sdp', 'https://sdp.example:8080', 'ticket', '7') === 'https://sdp.example:8080/WorkOrder.do?woMode=viewWO&woID=7');
check('no link over plain http, for an empty id, or an unknown type', Tab::deepLink('freshservice', 'http://x.example', 'ticket', '1') === null && Tab::deepLink('freshservice', 'https://x.example', 'ticket', '') === null && Tab::deepLink('freshservice', 'https://x.example', 'user', '1') === null);
check('the id is URL-encoded in the link', Tab::deepLink('freshservice', 'https://x.example', 'ticket', 'a b/c') === 'https://x.example/a/tickets/a%20b%2Fc');

// ---- run page helpers
check('summary: counters in one line, fetched left out', RunView::summary('{"users":{"created":3,"fetched":9},"tickets":{"failed":2}}') === 'users: 3 created; tickets: 2 failed');
check('summary: garbage in, empty out', RunView::summary('not json') === '' && RunView::summary('') === '');

// ---- what a browser may set
$f = WebRun::cleanFlags([]);
check('a dry run is the default', $f['dry_run'] === true && $f['ldap_lookup'] === true);
check('dry run: 0 ends it, 1 keeps it', WebRun::cleanFlags(['dry_run' => '0'])['dry_run'] === false && WebRun::cleanFlags(['dry_run' => '1'])['dry_run'] === true);
$f = WebRun::cleanFlags(['limit' => '-3', 'assign_profiles' => 'root', 'bogus' => 1, 'fallback_user' => '4x', 'create_missing_users' => 'on']);
check('flags are typed and whitelisted', $f['limit'] === 0 && $f['assign_profiles'] === null && !isset($f['bogus']) && $f['fallback_user'] === 4 && $f['create_missing_users'] === true);

// ---- the endpoint, checked in the source (the HTTP layer is covered on staging 2)
$a = (string) file_get_contents(ITSM_ROOT . '/ajax/run.php');
check('ajax: POST only', str_contains($a, "!== 'POST'") && str_contains($a, '405'));
check('ajax: needs the run right with Update', str_contains($a, 'RightDefinition::RUN, UPDATE'));
check('ajax: logged-in users only', str_contains($a, 'Session::checkLoginUser()'));
check('ajax: never reads $_GET or $_REQUEST', !str_contains($a, '$_GET') && !str_contains($a, '$_REQUEST'));
check('ajax: never calls checkCSRF itself (GLPI 11 checks the header before the file runs)', !str_contains($a, 'checkCSRF'));
check('ajax: only start, step and abort', preg_match_all("/case '(\w+)':/", $a, $m) === 3 && $m[1] === ['start', 'step', 'abort']);
check('ajax: modules are intersected with the known list', str_contains($a, 'array_intersect(Engine::MODULES'));
check('ajax: no api key anywhere', stripos($a, 'api_key') === false && stripos($a, 'apiKey') === false);
$w = (string) file_get_contents(ITSM_ROOT . '/src/WebRun.php');
check('web run: never reads the request', !str_contains($w, '$_POST') && !str_contains($w, '$_GET') && !str_contains($w, '$_REQUEST'));
check('web run: the position lives in the session, the browser holds only the run id', str_contains($w, '$_SESSION[self::SESSION_KEY]') && str_contains($w, "'run_id'"));
check('web run: the step is refused unless the run is a running web run of this session', str_contains($w, "\$run['runner'] !== 'web'") && str_contains($w, "\$run['status'] !== 'running'"));
$v = (string) file_get_contents(ITSM_ROOT . '/src/RunView.php');
check('run page: the form never carries the api key', stripos($v, 'api_key') === false);
check('run page: sends the CSRF header, not a body token', str_contains($v, "'X-Glpi-Csrf-Token'") && str_contains($v, "'X-Requested-With'"));
check('ajax: collects and discards anything printed while a batch runs (debug-mode warnings must not precede the JSON)', str_contains($a, 'ob_start()') && str_contains($a, 'ob_end_clean()'));
check('run page: a non-JSON answer is reported readably, and the run is stopped (lock released)', str_contains($v, 'did not answer JSON') && str_contains($v, "action: 'abort'"));
$rc = (string) file_get_contents(ITSM_ROOT . '/src/Console/RunCommand.php');
check('run command: --username is required and loads that user\'s session (the CLI has no session of its own)', str_contains($rc, "addOption('username'") && str_contains($rc, 'loadUserSession') && str_contains($rc, '--username is required'));
$t = (string) file_get_contents(ITSM_ROOT . '/src/Tab/ExternalReferenceTab.php');
check('tab: reads only id/name/adapter/base_url of a source, never the key', str_contains($t, "'SELECT' => ['id', 'name', 'adapter', 'base_url']") && stripos($t, 'api_key') === false);
check('tab: external links open with noopener', str_contains($t, 'noopener noreferrer'));
$r = (string) file_get_contents(ITSM_ROOT . '/src/ReviewView.php');
check('review: shows login and real name, never an e-mail', stripos($r, 'email') === false && stripos($r, 'useremails') === false);
check('review: the picker writes only the mapping (NeedsReview::resolve)', str_contains($r, 'NeedsReview::resolve') && !str_contains($r, '->update('));

done();
