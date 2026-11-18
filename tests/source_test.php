<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/glpi_stubs.php';
load_src('RightDefinition', 'Source');

use GlpiPlugin\Itsmmigrator\Source;

$good = ['name' => 'FS legacy', 'adapter' => 'freshservice', 'base_url' => 'https://example.freshservice.com', 'entities_id' => 5];

check('a good source has no errors', Source::errors($good, []) === []);
check('empty name refused', Source::errors(['name' => ' '] + $good, []) !== []);
check('unknown adapter refused', Source::errors(['adapter' => 'jira'] + $good, []) !== []);
check('root entity refused', Source::errors(['entities_id' => 0] + $good, []) !== []);
check('missing entity refused on add', Source::errors(array_diff_key($good, ['entities_id' => 1]), []) !== []);
check('http base_url refused (TLS is never optional)', Source::errors(['base_url' => 'http://sdp.example:8080'] + $good, []) !== []);
check('https base_url with port accepted', Source::errors(['base_url' => 'https://sdp.example:8080'] + $good, []) === []);
check('bad schedule refused', Source::errors(['schedule' => 'daily'] + $good, []) !== []);
check('bad JSON map refused', Source::errors(['status_map' => '{oops'] + $good, []) !== []);
check('valid JSON map accepted', Source::errors(['status_map' => '{"Open":2}'] + $good, []) === []);

// Update: only the posted keys are checked, so a partial post does not trip on stored values.
$stored = $good + ['id' => 1];
check('update with only api_key posted is fine', Source::errors(['api_key' => 'x'], $stored) === []);
check('update cannot move the source to root', Source::errors(['entities_id' => 0], $stored) !== []);

$enc = static fn (string $s): string => 'ENC(' . $s . ')';
$out = Source::prepare(['api_key' => 'secret', 'base_url' => ' https://x.example/ ', 'is_recursive' => 1, 'options' => ''], $enc);
check('api_key is encrypted', ($out['api_key'] ?? '') === 'ENC(secret)');
check('empty api_key keeps the stored one (key dropped from the update)', !array_key_exists('api_key', Source::prepare(['api_key' => ''], $enc)));
check('is_recursive is never passed on', !array_key_exists('is_recursive', $out));
check('base_url is trimmed, trailing slash removed', ($out['base_url'] ?? '') === 'https://x.example');
check('empty JSON field stored as NULL', array_key_exists('options', $out) && $out['options'] === null);
check('plaintext key is never in the prepared input', !str_contains(json_encode($out), '"secret"'));

done();
