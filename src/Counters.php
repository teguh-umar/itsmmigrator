<?php

namespace GlpiPlugin\Itsmmigrator;

/** Per-module counters of a run (spec §3 runs.counters, §10 summary table). */
final class Counters
{
    public const KEYS = ['fetched', 'matched', 'created', 'linked', 'updated', 'skipped', 'failed', 'orphan', 'needs_review'];

    /** @var array<string, array<string,int>> */
    private array $data = [];

    public function add(string $module, string $key, int $n = 1): void
    {
        if (!in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("Unknown counter '$key'");
        }
        $this->data[$module][$key] = ($this->data[$module][$key] ?? 0) + $n;
    }

    public function get(string $module, string $key): int
    {
        return $this->data[$module][$key] ?? 0;
    }

    /** @return array<string, array<string,int>> every module with every key, zeros included */
    public function all(): array
    {
        $out = [];
        foreach ($this->data as $module => $_) {
            foreach (self::KEYS as $k) {
                $out[$module][$k] = $this->data[$module][$k] ?? 0;
            }
        }
        return $out;
    }

    public function hasFailures(): bool
    {
        foreach ($this->data as $row) {
            if (($row['failed'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }
}
