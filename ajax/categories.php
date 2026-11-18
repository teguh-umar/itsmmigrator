<?php

use GlpiPlugin\Itsmmigrator\CategoryListing;
use GlpiPlugin\Itsmmigrator\RightDefinition;

/**
 * The source form's "show the source's categories" button: POST only, logged in, the config right with Update
 * (it uses the stored API key to read the source), CSRF checked by GLPI's listener on the X-Glpi-Csrf-Token
 * header. It reads one value (sources_id), makes ONE read-only request to the source, returns category paths
 * and nothing else, and writes and logs nothing.
 */
include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

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
if (!Session::haveRight(RightDefinition::CONFIG, UPDATE)) {
    $reply(403, ['success' => false, 'message' => 'Not allowed']);
}

$reply(200, CategoryListing::forSource((int) ($_POST['sources_id'] ?? 0)));
