<?php

namespace GlpiPlugin\Itsmmigrator;

use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalCategory;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalGroup;
use GlpiPlugin\Itsmmigrator\Adapter\Dto\ExternalUser;
use GlpiPlugin\Itsmmigrator\Adapter\SourceAdapterInterface;
use GlpiPlugin\Itsmmigrator\Writer\CategoryWriter;
use GlpiPlugin\Itsmmigrator\Writer\GroupWriter;
use GlpiPlugin\Itsmmigrator\Writer\UserWriter;

/**
 * Runs the modules of one source, in dependency order, under the global lock (spec §5).
 *
 * This build handles users, groups and categories. The other modules (kb, assets, tickets, problems,
 * changes) are named in MODULES so the CLI and the UI already know them, and are reported as "not
 * built yet" instead of being silently ignored.
 */
final class Engine
{
    /** All modules in the order they must run (users before groups: groups need the user map). */
    public const MODULES = ['users', 'groups', 'categories', 'kb', 'assets', 'tickets', 'problems', 'changes'];

    private const IMPLEMENTED = ['users', 'groups', 'categories'];

    /**
     * True while the engine writes. TicketGuard (M4) lets engine writes through and blocks everyone else.
     */
    public static bool $writing = false;

    private UserWriter $users;
    private GroupWriter $groups;
    private CategoryWriter $categories;

    public function __construct(
        private readonly SourceAdapterInterface $adapter,
        ?UserWriter $users = null,
        ?GroupWriter $groups = null,
        ?CategoryWriter $categories = null,
    ) {
        $this->users      = $users ?? new UserWriter();
        $this->groups     = $groups ?? new GroupWriter();
        $this->categories = $categories ?? new CategoryWriter();
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
     * @param array<string,mixed> $source  the sources row
     * @param list<string> $modules        requested modules (empty = all)
     * @param array<string,bool|int|string|null> $flags dry_run, retry_failed, recreate_missing, force_unlock,
     *        create_missing_users, activate_created_users, ldap_lookup, assign_profiles, limit
     * @param callable(string):void|null $logger
     * @return array{run_id: int, status: string, counters: array<string,array<string,int>>}
     * @throws LockException when another run holds the lock
     */
    public function run(array $source, string $runner, array $modules, array $flags, ?callable $logger = null): array
    {
        $plan = self::planModules($modules);
        $log  = $logger ?? static function (string $m): void {
        };
        foreach ($plan['unknown'] as $u) {
            $log("Unknown module '$u' ignored");
        }

        $dry   = !empty($flags['dry_run']);
        $runId = Lock::acquire((int) $source['id'], $runner, $plan['run'], $dry, null, !empty($flags['force_unlock']));

        $counters = new Counters();
        $options  = json_decode((string) ($source['options'] ?? ''), true);
        $ctx      = new Context($source, is_array($options) ? $options : [], $flags, $counters, $log);
        $status   = 'success';
        $message  = '';
        $prev     = self::$writing;
        self::$writing = true;

        try {
            $log(sprintf('Run %d on source "%s" (%s), target entity %d%s', $runId, (string) $source['name'], $ctx->adapterKey(), $ctx->targetEntity(), $dry ? ', DRY RUN' : ''));
            foreach ($plan['run'] as $module) {
                if (!in_array($module, self::IMPLEMENTED, true)) {
                    $log("Module '$module' is not built yet: skipped");
                    continue;
                }
                if (!$this->adapter->supports($module)) {
                    $log("Module '$module' is not supported by the {$ctx->adapterKey()} adapter: skipped");
                    continue;
                }
                $this->runModule($module, $ctx, $runId);
            }
            if ($counters->hasFailures()) {
                $status = 'partial';
            }
        } catch (\Throwable $e) {
            // A fetch or infrastructure error: the run stops, the rows already written stay valid.
            $status  = 'failed';
            $message = $e->getMessage();
            $log('Run FAILED: ' . $message);
        } finally {
            self::$writing = $prev;
            Lock::release($runId, $status, $counters->all(), $message);
        }

        return ['run_id' => $runId, 'status' => $status, 'counters' => $counters->all()];
    }

    private function runModule(string $module, Context $ctx, int $runId): void
    {
        $limit = (int) ($ctx->flag('limit') ?? 0);
        $seen  = 0;
        $page  = 1;
        $ctx->log("Module $module: start");

        do {
            $result = $this->adapter->fetchPage($module, $page, null);
            foreach ($result->items as $item) {
                $ctx->counters->add($module, 'fetched');
                match (true) {
                    $item instanceof ExternalUser     => $this->users->process($item, $ctx),
                    $item instanceof ExternalGroup    => $this->groups->process($item, $ctx),
                    $item instanceof ExternalCategory => $this->categories->process($item, $ctx),
                    default => throw new \LogicException('Adapter returned ' . $item::class . ' for module ' . $module),
                };
                $seen++;
                if ($limit > 0 && $seen >= $limit) {
                    $ctx->log("Module $module: --limit $limit reached");
                    return;
                }
            }
            // One heartbeat per batch keeps the lock alive (spec §5 rule 4).
            Lock::heartbeat($runId);
            $page++;
        } while ($result->hasMore);

        $ctx->log("Module $module: done");
    }
}
