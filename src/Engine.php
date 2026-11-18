<?php

namespace GlpiPlugin\Itsmmigrator;

use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalCategory;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalGroup;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalItem;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalTicket;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalUser;
use GlpiPlugin\Itsmmigrator\Adapter\SourceAdapterInterface;
use GlpiPlugin\Itsmmigrator\Writer\CategoryWriter;
use GlpiPlugin\Itsmmigrator\Writer\GroupWriter;
use GlpiPlugin\Itsmmigrator\Writer\TicketWriter;
use GlpiPlugin\Itsmmigrator\Writer\UserWriter;

/**
 * Runs the modules of one source, in dependency order, under the global lock (spec §5).
 *
 * One code path for CLI and web: a run is a list of modules and a position (module index, page,
 * cursor, offset in the page, items seen). `step()` moves the position forward by at most one batch.
 * The CLI loops `step()` with an unlimited batch (one page per step); the web asks for one small batch
 * per request and keeps the position in the PHP session, so no request outlives the browser limit.
 *
 * Modules built so far: users, groups, categories, tickets. The others (kb, assets, problems, changes)
 * are named in MODULES so the CLI and the UI already know them, and are reported as "not built yet".
 */
final class Engine
{
    /** All modules in the order they must run (users before groups: groups need the user map). */
    public const MODULES = ['users', 'groups', 'categories', 'kb', 'assets', 'tickets', 'problems', 'changes'];

    public const IMPLEMENTED = ['users', 'groups', 'categories', 'tickets'];

    /** Items per web request. A ticket costs several source requests and many GLPI writes. */
    public const WEB_BATCH_TICKETS = 10;
    public const WEB_BATCH_OTHER   = 100;

    /**
     * True while the engine writes. TicketGuard (M4) lets engine writes through and blocks everyone else.
     */
    public static bool $writing = false;

    private UserWriter $users;
    private GroupWriter $groups;
    private CategoryWriter $categories;
    private TicketWriter $tickets;

    public function __construct(
        private readonly SourceAdapterInterface $adapter,
        ?UserWriter $users = null,
        ?GroupWriter $groups = null,
        ?CategoryWriter $categories = null,
        ?TicketWriter $tickets = null,
    ) {
        $this->users      = $users ?? new UserWriter();
        $this->groups     = $groups ?? new GroupWriter();
        $this->categories = $categories ?? new CategoryWriter();
        $this->tickets    = $tickets ?? new TicketWriter();
    }

    /**
     * Modules to run: the requested ones (default all), in canonical order. Pure.
     *
     * @param list<string> $requested empty = all
     * @return array{run: list<string>, unknown: list<string>}
     */
    public static function planModules(array $requested): array
    {
        $requested = array_values(array_unique(array_map('trim', $requested)));
        $unknown   = array_values(array_diff($requested, self::MODULES));
        $run       = $requested === [] ? self::MODULES : array_values(array_intersect(self::MODULES, $requested));
        return ['run' => $run, 'unknown' => $unknown];
    }

    /**
     * The modules that will really run for this adapter, and a message for each one that will not.
     *
     * @param list<string> $requested
     * @return array{modules: list<string>, notes: list<string>}
     */
    public function modulesToRun(array $requested): array
    {
        $plan  = self::planModules($requested);
        $notes = [];
        foreach ($plan['unknown'] as $u) {
            $notes[] = "Unknown module '$u' ignored";
        }
        $run = [];
        foreach ($plan['run'] as $m) {
            if (!in_array($m, self::IMPLEMENTED, true)) {
                $notes[] = "Module '$m' is not built yet: skipped";
            } elseif (!$this->adapter->supports($m)) {
                $notes[] = "Module '$m' is not supported by the {$this->adapter->getSourceKey()} adapter: skipped";
            } else {
                $run[] = $m;
            }
        }
        return ['modules' => $run, 'notes' => $notes];
    }

    /** A fresh position for a run over $modules. @param list<string> $modules @return array<string,mixed> */
    public static function newPosition(array $modules): array
    {
        return ['modules' => array_values($modules), 'mi' => 0, 'page' => 1, 'cursor' => null, 'offset' => 0, 'seen' => 0];
    }

    /**
     * Process at most $batch items and move the position forward.
     *
     * @param array<string,mixed> $pos position (see newPosition()), updated in place
     * @param int $batch items per call (PHP_INT_MAX = the rest of the current page)
     * @return bool true when the run has no module left
     */
    public function step(array &$pos, Context $ctx, int $batch = PHP_INT_MAX): bool
    {
        $module = $pos['modules'][$pos['mi']] ?? null;
        if ($module === null) {
            return true;
        }

        $limit = (int) ($ctx->flag('limit') ?? 0);
        $since = self::effectiveSince($ctx->source, $ctx->flags);
        if ((int) $pos['offset'] === 0 && (int) $pos['page'] === 1) {
            $ctx->log("Module $module: start");
        }

        $result = $this->adapter->fetchPage($module, (int) $pos['page'], $since, $pos['cursor']);
        $slice  = array_slice($result->items, (int) $pos['offset'], max(1, $batch));
        $limitHit = false;

        foreach ($slice as $item) {
            $ctx->counters->add($module, 'fetched');
            $this->dispatch($module, $item, $ctx);
            if ($module === 'tickets' && $item->updatedAt !== null) {
                $iso = $item->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                if (!isset($pos['max_updated']) || $iso > $pos['max_updated']) {
                    $pos['max_updated'] = $iso;
                }
            }
            $pos['seen']++;
            if ($limit > 0 && $pos['seen'] >= $limit) {
                $ctx->log("Module $module: --limit $limit reached");
                $limitHit = true;
                break;
            }
        }
        $pos['offset'] += count($slice);

        $pageDone = $pos['offset'] >= count($result->items);
        if ($limitHit || ($pageDone && !$result->hasMore)) {
            $ctx->log("Module $module: done");
            if ($module === 'tickets' && !$limitHit) {
                $pos['tickets_complete'] = true; // the whole list was read: the high-water mark is trustworthy
            }
            $pos['mi']++;
            $pos['page'] = 1; $pos['cursor'] = null; $pos['offset'] = 0; $pos['seen'] = 0;
        } elseif ($pageDone) {
            $pos['page']++; $pos['cursor'] = $result->cursor; $pos['offset'] = 0;
        }

        return !isset($pos['modules'][$pos['mi']]);
    }

    /**
     * Whole run for the CLI.
     *
     * @param array<string,mixed> $source  the sources row
     * @param list<string> $modules        requested modules (empty = all)
     * @param array<string,bool|int|string|null> $flags dry_run, retry_failed, recreate_missing, force_unlock,
     *        create_missing_users, activate_created_users, ldap_lookup, assign_profiles, limit, since
     * @param callable(string):void|null $logger
     * @return array{run_id: int, status: string, counters: array<string,array<string,int>>}
     * @throws LockException when another run holds the lock
     */
    public function run(array $source, string $runner, array $modules, array $flags, ?callable $logger = null): array
    {
        $log = $logger ?? static function (string $m): void {
        };
        $plan = $this->modulesToRun($modules);
        foreach ($plan['notes'] as $n) {
            $log($n);
        }

        $dry   = !empty($flags['dry_run']);
        $runId = Lock::acquire((int) $source['id'], $runner, $plan['modules'], $dry, null, !empty($flags['force_unlock']));

        $counters = new Counters();
        $ctx      = new Context($source, self::decodeOptions($source), $flags, $counters, $log);
        $status   = 'success';
        $message  = '';
        $prev     = self::$writing;
        self::$writing = true;

        try {
            $log(sprintf('Run %d on source "%s" (%s), target entity %d%s', $runId, (string) $source['name'], $ctx->adapterKey(), $ctx->targetEntity(), $dry ? ', DRY RUN' : ''));
            $pos = self::newPosition($plan['modules']);
            do {
                $done = $this->step($pos, $ctx);
                // One heartbeat per batch keeps the lock alive (spec §5 rule 4).
                Lock::heartbeat($runId);
            } while (!$done);
            if ($counters->hasFailures()) {
                $status = 'partial';
            }
            self::advanceWatermark($source, $pos, $status, $flags, $log);
        } catch (\Throwable $e) {
            // A fetch or infrastructure error: the run stops, the rows already written stay valid.
            $status  = 'failed';
            $message = $e->getMessage();
            $log('Run FAILED: ' . $message);
        } finally {
            self::$writing = $prev;
            Lock::release($runId, $status, $counters->all(), $message);
        }

        return ['run_id' => $runId, 'status' => $status, 'counters' => $counters->all(), 'details' => $counters->details()];
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    public static function decodeOptions(array $source): array
    {
        $options = json_decode((string) ($source['options'] ?? ''), true);
        return is_array($options) ? $options : [];
    }

    private function dispatch(string $module, ExternalItem $item, Context $ctx): void
    {
        match (true) {
            $item instanceof ExternalUser     => $this->users->process($item, $ctx),
            $item instanceof ExternalGroup    => $this->groups->process($item, $ctx),
            $item instanceof ExternalCategory => $this->categories->process($item, $ctx),
            $item instanceof ExternalTicket   => $this->tickets->process($item, $this->adapter, $ctx),
            default => throw new \LogicException('Adapter returned ' . $item::class . ' for module ' . $module),
        };
    }

    /**
     * Where to start reading tickets (UTC). Pure.
     *
     * - an explicit `since` always wins;
     * - `full`, `retry_failed` and `recreate_missing` read everything: a failed or missing ticket can be
     *   older than the watermark, and the map makes re-reading harmless;
     * - otherwise the source's watermark minus a 2 hour overlap (clock skew, tickets updated while the
     *   last run was reading), or everything when there is no watermark yet.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $flags
     */
    public static function effectiveSince(array $source, array $flags): ?\DateTimeImmutable
    {
        $explicit = self::parseSince($flags['since'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }
        if (!empty($flags['full']) || !empty($flags['retry_failed']) || !empty($flags['recreate_missing'])) {
            return null;
        }
        $wm = $source['watermark'] ?? null;
        if (!is_string($wm) || trim($wm) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($wm, new \DateTimeZone(date_default_timezone_get())))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->modify('-2 hours');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The watermark to store after a run, or null to leave it alone (spec §8.4). Pure.
     *
     * Advanced to the highest `updated_at` seen, and only when: it is a real run (not a dry run), the
     * tickets module was read to its end (not cut by --limit), the run ended success or partial (a fetch
     * error makes it `failed`, and then nothing moves), something newer than the stored mark was seen.
     * It never moves backwards.
     *
     * @param array<string,mixed> $pos
     * @param array<string,mixed> $flags
     * @return string|null `Y-m-d H:i:s` in the PHP timezone
     */
    public static function watermarkToWrite(?string $current, array $pos, string $status, array $flags): ?string
    {
        // A dry run, a --limit run and a category-filtered run have not seen every ticket: nothing may move.
        if (!empty($flags['dry_run']) || (int) ($flags['limit'] ?? 0) > 0 || !empty($flags['category_filter'])) {
            return null;
        }
        if (!in_array($status, ['success', 'partial'], true) || empty($pos['tickets_complete']) || empty($pos['max_updated'])) {
            return null;
        }
        try {
            $seen = new \DateTimeImmutable((string) $pos['max_updated']);
            $tz   = new \DateTimeZone(date_default_timezone_get());
            if ($current !== null && trim($current) !== '' && $seen <= new \DateTimeImmutable($current, $tz)) {
                return null;
            }
            return $seen->setTimezone($tz)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Store the new watermark when watermarkToWrite() says so.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $pos
     * @param array<string,mixed> $flags
     * @param callable(string):void $log
     */
    public static function advanceWatermark(array $source, array $pos, string $status, array $flags, callable $log): void
    {
        global $DB;
        $flags['category_filter'] = (new Context($source, self::decodeOptions($source), $flags, new Counters()))->categoryFilter() !== [];
        $new = self::watermarkToWrite(isset($source['watermark']) ? (string) $source['watermark'] : null, $pos, $status, $flags);
        if ($new === null) {
            return;
        }
        $DB->update(Source::getTable(), ['watermark' => $new], ['id' => (int) $source['id']]);
        $log("Watermark of source {$source['id']} advanced to $new");
    }

    /** `--since` (ISO8601) -> UTC DateTimeImmutable; empty or unparsable -> null (everything). Pure. */
    public static function parseSince(bool|int|string|null $v): ?\DateTimeImmutable
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new \InvalidArgumentException("Cannot read --since '$v' (use ISO8601, e.g. 2024-01-01T00:00:00Z)");
        }
    }
}
