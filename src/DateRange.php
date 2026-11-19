<?php

namespace GlpiPlugin\Itsmmigrator;

/**
 * "Import tickets from DATE to DATE". Two readings of "the date of a ticket":
 *
 *  - `updated` (default): the ticket's last update time, the same clock the source's list is ordered and filtered
 *    by. The list is ascending, so the reader STOPS as soon as it meets a ticket updated after the end date.
 *  - `created`: the day the ticket was opened. The source cannot filter by it, so the list is read from the start
 *    date (a ticket created on or after it was updated on or after it too) and tickets outside the range are left
 *    out one by one. Slower, and it cannot stop early.
 *
 * `from` is the existing `import_since` option or the `--since` flag; `until` is `import_until` or `--until`.
 * A date alone ("2026-01-31") as an END means the whole day (23:59:59 UTC). Pure.
 */
final class DateRange
{
    public const UPDATED = 'updated';
    public const CREATED = 'created';

    public function __construct(
        public readonly ?\DateTimeImmutable $from = null,
        public readonly ?\DateTimeImmutable $until = null,
        public readonly string $basis = self::UPDATED,
    ) {
    }

    /**
     * @param array<string,mixed> $options the source's decoded options
     * @param array<string,mixed> $flags   the run's flags (since, until, date_basis)
     */
    public static function fromSettings(array $options, array $flags): self
    {
        // An empty flag (an empty form field) means "not given": the source option then applies.
        $pick  = static fn (string $flag, string $opt): mixed => (isset($flags[$flag]) && trim((string) $flags[$flag]) !== '') ? $flags[$flag] : ($options[$opt] ?? null);
        $basis = (string) ($pick('date_basis', 'import_date_basis') ?? self::UPDATED);
        $basis = $basis === self::CREATED ? self::CREATED : self::UPDATED;
        $from  = self::parse($pick('since', 'import_since'), false);
        $until = self::parse($pick('until', 'import_until'), true);
        return new self($from, $until, $basis);
    }

    /** A date ("2026-01-31") or an ISO time. As an end, a bare date means the end of that day. */
    public static function parse(mixed $v, bool $asEnd): ?\DateTimeImmutable
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        $v = trim($v);
        // Only real dates: DateTimeImmutable would also read "last week" or "tomorrow".
        if (!preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?)?$/', $v)) {
            return null;
        }
        try {
            $d = new \DateTimeImmutable($v, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
        if ($asEnd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $d = $d->setTime(23, 59, 59);
        }
        return $d->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Is the run narrower than "everything from the watermark on"? Then it has not seen all newer tickets, and the
     * watermark must not move: an end date, or the created-date reading (which re-reads from the start date).
     */
    public function limitsTheRun(): bool
    {
        return $this->until !== null || $this->basis === self::CREATED;
    }

    /**
     * Reading in ascending update order and this ticket is already past the end: nothing after it can be in range.
     * Only for the `updated` reading.
     */
    public function pastEnd(?\DateTimeImmutable $updated): bool
    {
        return $this->basis === self::UPDATED && $this->until !== null && $updated !== null && $updated > $this->until;
    }

    /** May this ticket be imported? Tickets without a date are not filtered out by a date. */
    public function allows(?\DateTimeImmutable $created, ?\DateTimeImmutable $updated): bool
    {
        $d = $this->basis === self::CREATED ? $created : $updated;
        if ($d === null) {
            return true;
        }
        if ($this->basis === self::CREATED && $this->from !== null && $d < $this->from) {
            return false; // the `updated` reading leaves the lower bound to the source's own filter
        }
        return $this->until === null || $d <= $this->until;
    }
}
