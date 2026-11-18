<?php

namespace GlpiPlugin\Itsmmigrator;

/** Per-module counters of a run (spec §3 runs.counters, §10 summary table). */
final class Counters
{
    public const KEYS = ['fetched', 'matched', 'created', 'linked', 'updated', 'skipped', 'failed', 'orphan', 'needs_review'];

    /** @var array<string, array<string,int>> */
    private array $data = [];

    /** @var array<string, array<string,int>> finer counts that explain the main ones (how a user was matched); never user data */
    private array $details = [];

    public function add(string $module, string $key, int $n = 1): void
    {
        if (!in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("Unknown counter '$key'");
        }
        $this->data[$module][$key] = ($this->data[$module][$key] ?? 0) + $n;
    }

    public function detail(string $module, string $key, int $n = 1): void
    {
        $this->details[$module][$key] = ($this->details[$module][$key] ?? 0) + $n;
    }

    /** @return array<string, array<string,int>> */
    public function details(): array
    {
        return $this->details;
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

    /**
     * Rebuild from a stored run's counters, so a web run can add to what earlier requests counted.
     *
     * @param array<string, array<string,int>> $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        foreach ($data as $module => $row) {
            foreach ((array) $row as $key => $n) {
                if (in_array($key, self::KEYS, true) && (int) $n > 0) {
                    $c->add((string) $module, (string) $key, (int) $n);
                }
            }
        }
        return $c;
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
