<?php

namespace GlpiPlugin\Itsmmigrator;

/**
 * The global single-runner lock (spec §5 rule 4): CLI, web and cron share it.
 *
 * A `running` row in the runs table IS the lock, because a web run spans many requests and no file
 * lock can. A file lock (flock) only makes the check-and-insert atomic, so two runners starting at
 * the same instant cannot both pass. The owner proves it is alive by a heartbeat on every batch; a
 * heartbeat older than STALE_AFTER means the owner probably died, but the row still blocks until an
 * admin forces the unlock (--force-unlock or the button) so a slow batch is never trampled.
 */
final class Lock
{
    public const STALE_AFTER = 900; // 15 minutes

    /**
     * Pure decision, so it can be tested without a database.
     *
     * @param list<array<string,mixed>> $running rows with status `running`
     * @param int  $now                          unix time
     * @return array{proceed: bool, abort_ids: list<int>, message: string, stale: bool}
     */
    public static function evaluate(array $running, int $now, bool $force): array
    {
        if ($running === []) {
            return ['proceed' => true, 'abort_ids' => [], 'message' => '', 'stale' => false];
        }

        $stale = true;
        $ids   = [];
        $first = $running[0];
        foreach ($running as $r) {
            $ids[] = (int) $r['id'];
            $beat  = strtotime((string) ($r['heartbeat_at'] ?? $r['started_at'] ?? '')) ?: 0;
            if ($now - $beat <= self::STALE_AFTER) {
                $stale = false;
                $first = $r;
            }
        }

        // Forcing is only allowed on a stale lock: a live run is never aborted from outside.
        if ($force && $stale) {
            return ['proceed' => true, 'abort_ids' => $ids, 'message' => '', 'stale' => true];
        }

        $msg = sprintf(
            'Run already active (source %d, runner %s, started_at %s)',
            (int) ($first['sources_id'] ?? 0),
            (string) ($first['runner'] ?? '?'),
            (string) ($first['started_at'] ?? '?')
        );
        if ($stale) {
            $msg .= '. Its heartbeat is older than 15 minutes: if it is dead, force the unlock.';
        }
        return ['proceed' => false, 'abort_ids' => [], 'message' => $msg, 'stale' => $stale];
    }

    /**
     * Take the lock and open the run row.
     *
     * @param list<string> $modules
     * @return int the runs.id
     * @throws LockException when another run is active
     */
    public static function acquire(
        int $sourcesId,
        string $runner,
        array $modules,
        bool $dryRun,
        ?string $since = null,
        bool $forceUnlock = false
    ): int {
        global $DB;

        $fh = self::open();
        try {
            if ($fh !== false) {
                flock($fh, LOCK_EX);
            }

            $running = [];
            foreach ($DB->request(['FROM' => Run::getTable(), 'WHERE' => ['status' => 'running'], 'ORDER' => 'id']) as $row) {
                $running[] = $row;
            }
            $verdict = self::evaluate($running, time(), $forceUnlock);
            if (!$verdict['proceed']) {
                throw new LockException($verdict['message'], $verdict['stale']);
            }

            $now = date('Y-m-d H:i:s');
            foreach ($verdict['abort_ids'] as $id) {
                $DB->update(Run::getTable(), [
                    'status'      => 'aborted',
                    'finished_at' => $now,
                    'message'     => 'Lock forced: heartbeat older than 15 minutes',
                ], ['id' => $id]);
            }

            $DB->insert(Run::getTable(), [
                'sources_id'   => $sourcesId,
                'runner'       => $runner,
                'modules'      => implode(',', $modules),
                'dry_run'      => $dryRun ? 1 : 0,
                'since'        => $since,
                'started_at'   => $now,
                'heartbeat_at' => $now,
                'status'       => 'running',
            ]);
            return (int) $DB->insertId();
        } finally {
            if ($fh !== false) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
    }

    public static function heartbeat(int $runId): void
    {
        global $DB;
        $DB->update(Run::getTable(), ['heartbeat_at' => date('Y-m-d H:i:s')], ['id' => $runId, 'status' => 'running']);
    }

    /**
     * Close the run and release the lock.
     *
     * @param array<string,array<string,int>> $counters module => counter => n
     */
    public static function release(int $runId, string $status, array $counters = [], string $message = ''): void
    {
        global $DB;
        $DB->update(Run::getTable(), [
            'status'      => $status,
            'finished_at' => date('Y-m-d H:i:s'),
            'counters'    => json_encode($counters),
            'message'     => mb_substr($message, 0, 2000),
        ], ['id' => $runId]);
    }

    /**
     * The admin's "Release stale lock" button: abort `running` rows whose heartbeat is older than
     * STALE_AFTER. A live run is never touched.
     *
     * @return int number of runs aborted
     */
    public static function releaseStale(): int
    {
        global $DB;
        $running = [];
        foreach ($DB->request(['FROM' => Run::getTable(), 'WHERE' => ['status' => 'running'], 'ORDER' => 'id']) as $row) {
            $running[] = $row;
        }
        $verdict = self::evaluate($running, time(), true);
        if (!$verdict['proceed'] || $verdict['abort_ids'] === []) {
            return 0;
        }
        foreach ($verdict['abort_ids'] as $id) {
            $DB->update(Run::getTable(), [
                'status'      => 'aborted',
                'finished_at' => date('Y-m-d H:i:s'),
                'message'     => 'Lock released by an administrator: heartbeat older than 15 minutes',
            ], ['id' => $id]);
        }
        return count($verdict['abort_ids']);
    }

    /** @return array<string,mixed>|null the run that holds the lock now, else null */
    public static function current(): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => Run::getTable(), 'WHERE' => ['status' => 'running'], 'ORDER' => 'id DESC', 'LIMIT' => 1]) as $row) {
            return $row;
        }
        return null;
    }

    /** @return resource|false */
    private static function open()
    {
        $dir = defined('GLPI_LOCK_DIR') ? GLPI_LOCK_DIR : sys_get_temp_dir();
        return @fopen(rtrim((string) $dir, '/\\') . DIRECTORY_SEPARATOR . 'itsmmigrator.lock', 'c');
    }
}
