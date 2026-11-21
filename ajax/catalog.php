<?php

use GlpiPlugin\Itsmmigrator\Catalog\CatalogImporter;
use GlpiPlugin\Itsmmigrator\RightDefinition;
use GlpiPlugin\Itsmmigrator\Source;

/**
 * The Run page's service-catalog endpoint: POST only, the CSRF token travels in the X-Glpi-Csrf-Token header and is
 * checked by GLPI's own listener before this file runs. It reads only: action (count|start|step), sources_id (an
 * integer) and token (32 hex characters, given by this endpoint). Nothing else. Every Form is created inactive; a second
 * click, a second tab or a retry never creates a Form twice (see CatalogImporter).
 *
 *   count: read the catalog and count what would be created; writes nothing.
 *   start: read the catalog, keep it for the next calls, make the first batch.
 *   step : make the next batch from the kept catalog.
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
if (!Session::haveRight(RightDefinition::RUN, UPDATE)) {
    $reply(403, ['success' => false, 'message' => 'Not allowed']);
}

$action = (string) ($_POST['action'] ?? '');
$src    = new Source();
if ((int) ($_POST['sources_id'] ?? 0) <= 0 || !$src->getFromDB((int) $_POST['sources_id'])) {
    $reply(404, ['success' => false, 'message' => 'No such source']);
}
if ($src->apiKey() === '') {
    $reply(409, ['success' => false, 'message' => 'No API key is stored for this source']);
}

try {
    switch ($action) {
        case 'count':
        case 'start':
            $prep = CatalogImporter::prepare($src);
            $cat  = (array) CatalogImporter::load($prep['token']);
            if ($action === 'count') {
                CatalogImporter::forget($prep['token']);
                $r = CatalogImporter::process($src, $cat, true, null);
                $reply(200, ['success' => true, 'done' => true, 'dry' => true, 'counts' => $r['counts'], 'kinds' => $r['kinds']]);
            }
            $r = CatalogImporter::process($src, $cat, false, CatalogImporter::BATCH);
            if ($r['done'] || $r['busy']) {
                CatalogImporter::forget($prep['token']);
            }
            $reply(200, ['success' => true, 'token' => $prep['token'], 'done' => $r['done'], 'busy' => $r['busy'], 'remaining' => $r['remaining'], 'counts' => $r['counts'], 'kinds' => $r['kinds']]);

            // no break: $reply() never returns
        case 'step':
            $token = (string) ($_POST['token'] ?? '');
            $cat   = preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? CatalogImporter::load($token) : null;
            if ($cat === null) {
                $reply(410, ['success' => false, 'message' => 'The kept catalog is gone: start again (what is imported stays)']);
            }
            $r = CatalogImporter::process($src, $cat, false, CatalogImporter::BATCH);
            if ($r['done']) {
                CatalogImporter::forget($token);
            }
            $reply(200, ['success' => true, 'token' => $token, 'done' => $r['done'], 'busy' => $r['busy'], 'remaining' => $r['remaining'], 'counts' => $r['counts'], 'kinds' => $r['kinds']]);

        default:
            $reply(400, ['success' => false, 'message' => 'Unknown action']);
    }
} catch (\Throwable $e) {
    $reply(500, ['success' => false, 'message' => 'The catalog could not be read or written: ' . $e->getMessage()]);
}
