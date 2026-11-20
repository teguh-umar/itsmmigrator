<?php

namespace GlpiPlugin\Itsmmigrator;

/**
 * Everything a writer needs to know about the current run. Built by the Engine from the source row
 * and the CLI/web options. A writer never reads the request or the CLI input itself.
 */
final class Context
{
    /** @var callable(string):void */
    private $logger;

    /**
     * @param array<string,mixed> $source   the sources row
     * @param array<string,mixed> $options  decoded `options` JSON of the source (§4a, §4b, §6, §8)
     * @param array<string,bool|int|string|null> $flags run flags: dry_run, retry_failed, recreate_missing,
     *        create_missing_users, activate_created_users, ldap_lookup, fallback_user, assign_profiles
     * @param callable(string):void|null $logger
     */
    public function __construct(
        public readonly array $source,
        public readonly array $options,
        public readonly array $flags,
        public readonly Counters $counters,
        ?callable $logger = null,
    ) {
        $this->logger = $logger ?? static function (string $m): void {
        };
    }

    public function sourcesId(): int
    {
        return (int) $this->source['id'];
    }

    public function adapterKey(): string
    {
        return (string) $this->source['adapter'];
    }

    /** The target entity: every record the plugin creates goes here, non-recursive (spec §0 principle 5). */
    public function targetEntity(): int
    {
        return (int) $this->source['entities_id'];
    }

    public function dryRun(): bool
    {
        return !empty($this->flags['dry_run']);
    }

    public function flag(string $name, bool|int|string|null $default = null): bool|int|string|null
    {
        return $this->flags[$name] ?? $default;
    }

    /** Source option with a default. */
    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /** @return array{retry_failed: bool, recreate_missing: bool} for MapRepository::decide() */
    public function decideOptions(): array
    {
        return [
            'retry_failed'     => !empty($this->flags['retry_failed']),
            'recreate_missing' => !empty($this->flags['recreate_missing']),
        ];
    }

    /**
     * The category filter of this run: the CLI/web flag wins over the source option. Empty = no filter.
     *
     * @return list<list<string>>
     */
    public function categoryFilter(): array
    {
        $flag = CategoryFilter::parse($this->flags['categories'] ?? null);
        return $flag !== [] ? $flag : CategoryFilter::parse($this->options['ticket_categories'] ?? null);
    }

    /** The import range of this run (from, until, which date): flags win over the source options. */
    public function dateRange(): DateRange
    {
        return DateRange::fromSettings($this->options, $this->flags);
    }

    /** @var (callable():void)|null refreshes the run's heartbeat */
    private $beat = null;
    private int $lastTick = 0;
    private int $lastBeat = 0;

    /** Gap in wall-clock seconds between two items above which a note is logged: the computer was probably asleep. */
    public const SLEEP_GAP = 300;

    /** @param callable():void $beat */
    public function onTick(callable $beat): void
    {
        $this->beat = $beat;
    }

    /**
     * Called after every item. Refreshes the heartbeat at most every 10 s (a ticket takes seconds and a page of 100 can
     * take 15 minutes: one beat per page made a healthy run look dead), and says so when the clock jumped, which is what
     * a sleeping computer looks like. Time is a parameter so the rule can be tested.
     */
    public function tick(?int $now = null): void
    {
        $now ??= time();
        if ($this->lastTick > 0 && $now - $this->lastTick > self::SLEEP_GAP) {
            $this->log(sprintf('Note: %d minutes passed between two items (the computer was probably asleep); the run goes on', intdiv($now - $this->lastTick, 60)));
            $this->lastBeat = 0;
        }
        $this->lastTick = $now;
        if ($this->beat !== null && $now - $this->lastBeat >= 10) {
            $this->lastBeat = $now;
            ($this->beat)();
        }
    }

    public function log(string $message): void
    {
        ($this->logger)($message);
    }
}
