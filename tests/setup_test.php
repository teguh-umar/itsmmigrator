<?php

require __DIR__ . '/bootstrap.php';

// Claimed values (hub §2): menu 85, PGN Suite tab 85, no AssetTab.
$setup = file_get_contents(ITSM_ROOT . '/setup.php');
check('version is an rc', preg_match("/'PLUGIN_ITSMMIGRATOR_VERSION', '\\d+\\.\\d+\\.\\d+-rc\\d+'/", $setup) === 1);
check('GLPI range 11.0.0 - 11.0.99', str_contains($setup, "'11.0.0'") && str_contains($setup, "'11.0.99'"));
check('csrf_compliant is declared', str_contains($setup, "['csrf_compliant']['itsmmigrator'] = true"));
check('api_key is a secured field', str_contains($setup, "glpi_plugin_itsmmigrator_sources.api_key"));
check('no AssetTab registered', !str_contains($setup, 'AssetTab::register'));
check('Profile rights tab registered', str_contains($setup, 'Profile::class'));

$menu = file_get_contents(ITSM_ROOT . '/src/Menu.php');
check('menu order 85', str_contains($menu, 'ORDER = 85'));
$cfg = file_get_contents(ITSM_ROOT . '/src/SuiteConfig.php');
check('PGN Suite tab order 85', str_contains($cfg, 'ORDER = 85'));

$cron = file_get_contents(ITSM_ROOT . '/src/Cron/SyncTask.php');
check('cron task is SdpSync with a cronSdpSync method', str_contains($cron, "TASK = 'SdpSync'") && str_contains($cron, 'function cronSdpSync'));

// Shared files: byte-identical to the canonical copy (LF-normalised), and match install/pgnsuite.sha256.
$expected = [];
foreach (preg_split('/\R/', trim((string) file_get_contents(ITSM_ROOT . '/install/pgnsuite.sha256'))) as $line) {
    if (preg_match('/^([0-9a-f]{64})\s+(\S+)$/', $line, $m)) {
        $expected[$m[2]] = $m[1];
    }
}
foreach (['AssetTab.php', 'ConfigTab.php', 'Menu.php'] as $f) {
    $mine  = lf_hash(ITSM_ROOT . '/lib/pgnsuite/' . $f);
    $canon = lf_hash(SHARED . '/' . $f);
    check("lib/pgnsuite/$f equals the canonical copy", $mine !== null && $mine === $canon);
    check("lib/pgnsuite/$f matches install/pgnsuite.sha256", ($expected[$f] ?? null) === $mine);
}
$mine  = lf_hash(ITSM_ROOT . '/front/pgnsuite.settings.php');
$canon = lf_hash(SHARED . '/front/pgnsuite.settings.php');
check('front/pgnsuite.settings.php equals the canonical copy', $mine !== null && $mine === $canon);

$attr = (string) file_get_contents(ITSM_ROOT . '/.gitattributes');
check('.gitattributes marks the shared files -text',
    str_contains($attr, 'lib/pgnsuite/*.php -text') && str_contains($attr, 'front/pgnsuite.settings.php -text'));

// The three plugin commands must carry GLPI 11's mandatory plugins:<key>: prefix.
foreach (['Run', 'Verify', 'Purge'] as $c) {
    $src = file_get_contents(ITSM_ROOT . "/src/Console/{$c}Command.php");
    check("{$c}Command is named plugins:itsmmigrator:" . strtolower($c), str_contains($src, "setName('plugins:itsmmigrator:" . strtolower($c) . "')"));
}
$run = file_get_contents(ITSM_ROOT . '/src/Console/RunCommand.php');
check('RunCommand has no API key option', !preg_match("/addOption\\('(api-key|api_key|key|token)'/", $run));

// The docs name the commands GLPI actually loads, never the spec's bare names, and never an API key option.
foreach (['README.md', 'docs/runbook-v0.1.0-rc25.md'] as $doc) {
    $d = (string) file_get_contents(ITSM_ROOT . '/' . $doc);
    check("$doc uses plugins:itsmmigrator:* command names", str_contains($d, 'plugins:itsmmigrator:run') && str_contains($d, 'plugins:itsmmigrator:verify'));
    check("$doc never shows a bare itsmmigrator:<command>", preg_match('/(?<!plugins:)\bitsmmigrator:(run|verify|purge)\b/', $d) === 0);
}
$rb = (string) file_get_contents(ITSM_ROOT . '/docs/runbook-v0.1.0-rc25.md');
check('runbook version matches setup.php', preg_match("/'PLUGIN_ITSMMIGRATOR_VERSION', '([^']+)'/", $setup, $m) === 1 && str_contains($rb, $m[1]));
check('runbook says the key is never typed on a command line', str_contains($rb, 'Never put the Freshservice API key'));
$ver = (string) file_get_contents(ITSM_ROOT . '/src/Verifier.php');
check('Verifier never writes', preg_match('/->(insert|update|delete)\(|DROP |TRUNCATE /i', $ver) === 0);

done();
