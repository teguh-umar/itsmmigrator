<?php

namespace GlpiPlugin\Itsmmigrator;

use GlpiPlugin\Itsmmigrator\Adapter\AdapterFactory;
use GlpiPlugin\Itsmmigrator\Matching\GlpiLdapLookup;
use GlpiPlugin\Itsmmigrator\Writer\UserWriter;
use Session;

/**
 * A run driven from the browser: `start`, then `step` over and over, one small batch per request
 * (spec §11). It takes the same global lock as the CLI and cron. The position of the run (module,
 * page, cursor, offset) and the flags live in the PHP session on the SERVER, so the browser cannot
 * change them mid-run; the browser only holds the run id. A run whose session is lost simply
 * stops heartbeating and can be released after 15 minutes.
 *
 * The caller (ajax/run.php) has already checked the rights and that the request is a POST with a
 * valid CSRF token. Nothing here reads the HTTP request: the endpoint passes plain values in.
 */
final class WebRun
{
    public const SESSION_KEY = 'plugin_itsmmigrator_runs';

    /** Flags a browser may set, with their type. Anything else is dropped. */
    private const FLAGS = [
        'dry_run' => 'bool', 'full' => 'bool', 'retry_failed' => 'bool', 'recreate_missing' => 'bool', 'create_missing_users' => 'bool',
        'activate_created_users' => 'bool', 'ldap_lookup' => 'bool', 'limit' => 'int', 'fallback_user' => 'int',
        'assign_profiles' => 'profiles', 'since' => 'string', 'until' => 'string', 'date_basis' => 'basis', 'approvals' => 'bool',
    ];

    /**
     * Keep only the allowed flags, typed. A dry run is the DEFAULT: it is switched off only by an
     * explicit `dry_run` = 0. Pure.
     *
     * @param array<string,mixed> $raw
     * @return array<string,bool|int|string|null>
     */
    public static function cleanFlags(array $raw): array
    {
        $out = ['dry_run' => true, 'ldap_lookup' => true];
        foreach (self::FLAGS as $name => $type) {
            if (!array_key_exists($name, $raw)) {
                continue;
            }
            $v = $raw[$name];
            $out[$name] = match ($type) {
                'bool'     => in_array($v, [true, 1, '1', 'true', 'on'], true),
                'int'      => max(0, (int) $v),
                'profiles' => in_array($v, ['none', 'requester_only', 'all'], true) ? $v : null,
                'basis'    => in_array($v, ['updated', 'created'], true) ? $v : null,
                default    => is_string($v) ? trim($v) : null,
            };
        }
        return $out;
    }

    /**
     * @param list<string> $modules
     * @param array<string,mixed> $rawFlags
     * @return array<string,mixed> ['success'=>bool, 'message'?, 'locked'?, 'run_id'?, 'modules'?, 'notes'?]
     */
    public static function start(int $sourcesId, array $modules, array $rawFlags): array
    {
        $source = new Source();
        if (!$source->getFromDB($sourcesId)) {
            return ['success' => false, 'message' => __('Unknown source.', 'itsmmigrator')];
        }
        if ((int) $source->fields['is_active'] !== 1) {
            return ['success' => false, 'message' => __('The source is not active.', 'itsmmigrator')];
        }
        if ($source->apiKey() === '') {
            return ['success' => false, 'message' => __('No API key is stored for this source.', 'itsmmigrator')];
        }
        if (!Session::haveAccessToEntity((int) $source->fields['entities_id'])) {
            return ['success' => false, 'message' => __('You have no access to the target entity of this source.', 'itsmmigrator')];
        }

        $flags = self::cleanFlags($rawFlags);
        try {
            Engine::parseSince($flags['since'] ?? null); // refuse a bad date now, not in the middle of the run
            if ((string) ($flags['until'] ?? '') !== '' && DateRange::parse($flags['until'], true) === null) {
                return ['success' => false, 'message' => __('The end date cannot be read: use a date like 2026-01-31.', 'itsmmigrator')];
            }
            $adapter = AdapterFactory::make($source->fields, $source->apiKey());
            $test = $adapter->testConnection();
            if (!$test['ok']) {
                return ['success' => false, 'message' => sprintf(__('Connection test failed: %s', 'itsmmigrator'), $test['message'])];
            }
            $engine = new Engine($adapter, new UserWriter(new GlpiLdapLookup()));
            $plan   = $engine->modulesToRun($modules);
            if ($plan['modules'] === []) {
                return ['success' => false, 'message' => __('No module to run.', 'itsmmigrator'), 'notes' => $plan['notes']];
            }
            $runId = Lock::acquire($sourcesId, 'web', $plan['modules'], (bool) $flags['dry_run'], null, false);
        } catch (LockException $e) {
            $cur = Lock::current();
            return ['success' => false, 'locked' => true, 'message' => $e->getMessage(), 'owner' => $cur['runner'] ?? null, 'started_at' => $cur['started_at'] ?? null, 'stale' => $e->stale];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $_SESSION[self::SESSION_KEY][$runId] = [
            'sources_id' => $sourcesId,
            'pos'        => Engine::newPosition($plan['modules']),
            'flags'      => $flags,
            'counters'   => [],
        ];
        return ['success' => true, 'run_id' => $runId, 'modules' => $plan['modules'], 'notes' => $plan['notes'], 'dry_run' => (bool) $flags['dry_run']];
    }

    /**
     * One batch. Returns what the page shows: logs of this batch, counters so far, progress, done.
     *
     * @return array<string,mixed>
     */
    public static function step(int $runId): array
    {
        global $DB;
        $state = $_SESSION[self::SESSION_KEY][$runId] ?? null;
        $run   = $DB->request(['FROM' => Run::getTable(), 'WHERE' => ['id' => $runId]])->current();
        if ($state === null || $run === null || $run['status'] !== 'running' || $run['runner'] !== 'web' || (int) $run['sources_id'] !== (int) $state['sources_id']) {
            return ['success' => false, 'message' => __('This run is not active in your session.', 'itsmmigrator')];
        }

        $logs     = [];
        $logger   = static function (string $m) use (&$logs): void {
            $logs[] = sprintf('[%s] %s', date('H:i:s'), $m);
        };
        $counters = Counters::fromArray((array) $state['counters']);
        $status   = null;
        $message  = '';
        $done     = false;

        $prev = Engine::$writing;
        Engine::$writing = true;
        try {
            $source = new Source();
            $source->getFromDB((int) $state['sources_id']);
            $fields = $source->fields;
            $key    = $source->apiKey();
            unset($fields['api_key']);

            $adapter = AdapterFactory::make($source->fields, $key);
            $engine  = new Engine($adapter, new UserWriter(new GlpiLdapLookup($logger)));
            $ctx     = new Context($fields, Engine::decodeOptions($fields), $state['flags'], $counters, $logger);

            $pos    = $state['pos'];
            $module = $pos['modules'][$pos['mi']] ?? '';
            $batch  = $module === 'tickets' ? Engine::WEB_BATCH_TICKETS : Engine::WEB_BATCH_OTHER;
            $done   = $engine->step($pos, $ctx, $batch);

            $state['pos']      = $pos;
            $state['counters'] = $counters->all();
            $_SESSION[self::SESSION_KEY][$runId] = $state;
            Lock::heartbeat($runId);
            $DB->update(Run::getTable(), ['counters' => json_encode($counters->all())], ['id' => $runId]);
        } catch (\Throwable $e) {
            $done    = true;
            $status  = 'failed';
            $message = $e->getMessage();
            $logs[]  = sprintf('[%s] Run FAILED: %s', date('H:i:s'), $message);
        } finally {
            Engine::$writing = $prev;
        }

        if ($done) {
            $status ??= $counters->hasFailures() ? 'partial' : 'success';
            if (isset($state['pos']) && isset($state['flags']) && isset($fields)) {
                Engine::advanceWatermark($fields, $state['pos'], $status, $state['flags'], $logger);
            }
            Lock::release($runId, $status, $counters->all(), $message);
            unset($_SESSION[self::SESSION_KEY][$runId]);
        }

        return [
            'success'  => true,
            'done'     => $done,
            'status'   => $status,
            'logs'     => $logs,
            'counters' => $counters->all(),
            'progress' => $done ? null : [
                'module' => $state['pos']['modules'][$state['pos']['mi']] ?? null,
                'index'  => $state['pos']['mi'],
                'of'     => count($state['pos']['modules']),
                'page'   => $state['pos']['page'],
            ],
        ];
    }

    /** Stop a run from the page; what is already written stays (and is resumable). @return array<string,mixed> */
    public static function abort(int $runId): array
    {
        global $DB;
        $state = $_SESSION[self::SESSION_KEY][$runId] ?? null;
        $run   = $DB->request(['FROM' => Run::getTable(), 'WHERE' => ['id' => $runId]])->current();
        if ($state === null || $run === null || $run['status'] !== 'running' || $run['runner'] !== 'web') {
            return ['success' => false, 'message' => __('This run is not active in your session.', 'itsmmigrator')];
        }
        Lock::release($runId, 'aborted', (array) $state['counters'], 'Stopped from the Run page');
        unset($_SESSION[self::SESSION_KEY][$runId]);
        return ['success' => true, 'done' => true, 'status' => 'aborted', 'counters' => $state['counters'], 'logs' => []];
    }
}
