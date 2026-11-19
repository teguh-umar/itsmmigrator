<?php

use GlpiPlugin\Itsmmigrator\Engine;
use GlpiPlugin\Itsmmigrator\RightDefinition;
use GlpiPlugin\Itsmmigrator\WebRun;

/**
 * The Run page's endpoint (spec §11): POST only; the CSRF token travels in the X-Glpi-Csrf-Token header
 * and is checked by GLPI's own listener before this file runs; takes the same global lock as the CLI;
 * one batch per request. It accepts only: action (start|step|abort), sources_id, run_id, modules, and
 * the run flags listed in WebRun::cleanFlags(). Nothing else is read.
 */
include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

// Anything that prints while a batch runs (a PHP warning shown by GLPI in debug mode, a notice from a core call)
// would be put in front of the JSON and break the page. Everything printed is collected and thrown away; only
// the JSON is sent. (The warnings still reach the PHP log.)
$bufferLevel = ob_get_level();
ob_start();

$reply = static function (int $code, array $body) use ($bufferLevel): never {
    while (ob_get_level() > $bufferLevel) {
        ob_end_clean();
    }
    http_response_code($code);
    echo json_encode($body);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $reply(405, ['success' => false, 'message' => 'POST only']);
}
Session::checkLoginUser();
if (!Session::haveRight(RightDefinition::RUN, UPDATE)) {
    $reply(403, ['success' => false, 'message' => 'Not allowed']);
}

$action = (string) ($_POST['action'] ?? '');

switch ($action) {
    case 'start':
        $modules = array_values(array_intersect(Engine::MODULES, array_map('strval', (array) ($_POST['modules'] ?? []))));
        $flags   = array_intersect_key($_POST, array_flip(['dry_run', 'limit', 'retry_failed', 'recreate_missing', 'create_missing_users', 'activate_created_users', 'ldap_lookup', 'assign_profiles', 'fallback_user', 'since', 'until', 'date_basis']));
        $reply(200, WebRun::start((int) ($_POST['sources_id'] ?? 0), $modules, $flags));

        // no break: $reply() never returns
    case 'step':
        $reply(200, WebRun::step((int) ($_POST['run_id'] ?? 0)));

    case 'abort':
        $reply(200, WebRun::abort((int) ($_POST['run_id'] ?? 0)));

    default:
        $reply(400, ['success' => false, 'message' => 'Unknown action']);
}
