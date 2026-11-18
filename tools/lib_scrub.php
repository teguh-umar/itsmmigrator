<?php

/**
 * Structure-preserving scrubber for recorded API answers (used by tools/fs_record.php, pinned by tests/scrub_test.php).
 *
 * Rule of thumb: KEEP what the adapter needs to be correct (key names, nesting, JSON types, enum-like numbers and
 * strings, dates, the shape of links and ids' relations), REPLACE everything a person or the company wrote or is.
 * Unknown strings are replaced, not kept: forgetting a field leaks nothing.
 */
final class FsScrubber
{
    /** String values that are enum-like and safe to keep, by key. */
    private const KEEP_STRING_KEYS = ['type', 'content_type', 'field_type', 'status_name', 'priority_name', 'source_name', 'sort_order',
        'language', 'time_format', 'time_zone', 'role_name', 'ticket_type'];

    /** Keys whose strings are a person's or company's own words. */
    private const WORD_KEYS = ['name', 'first_name', 'last_name', 'subject', 'title', 'description', 'description_text', 'body', 'body_text',
        'note', 'job_title', 'address', 'location_name', 'department_names', 'department', 'phone', 'mobile_phone_number', 'work_phone_number',
        'resolution', 'content', 'comment', 'summary', 'label', 'label_for_requesters', 'category', 'sub_category', 'item_category'];

    /** Fields of the ticket form whose choices are the account's own status / priority wording: generic, kept so the mapping can be tested. */
    private const KEEP_VOCAB_FIELDS = ['status', 'priority', 'urgency', 'impact', 'ticket_type', 'source'];

    private bool $keepVocab = false;

    /** @var array<string,int> value => number, per kind */
    private array $dict = [];
    /** @var array<string,int> */
    private array $counter = [];

    /**
     * @param mixed  $data decoded JSON
     * @param string $kind user | group | ticket | note | task | form (decides which `name`/`label` keys are identifiers)
     * @return mixed
     */
    public function scrub(mixed $data, string $kind): mixed
    {
        return $this->walk($data, '', $kind, false);
    }

    private function walk(mixed $v, string $key, string $kind, bool $inChoices): mixed
    {
        if (is_array($v)) {
            $isList = array_is_list($v);
            $saved  = $this->keepVocab;
            if ($kind === 'form' && !$isList && isset($v['name']) && is_string($v['name']) && in_array($v['name'], self::KEEP_VOCAB_FIELDS, true)) {
                $this->keepVocab = true;
            }
            $out = [];
            foreach ($v as $k => $child) {
                $ck = $isList ? $key : (string) $k;
                $choices = $inChoices || $ck === 'choices' || $ck === 'nested_options';
                $out[$k] = $this->walk($child, $ck, $kind, $choices);
            }
            $this->keepVocab = $saved;
            return $out;
        }
        if (is_int($v) || is_float($v)) {
            // A 10+ digit number is an id whatever its key; a small one under an id key is an enumeration code (2 = Open) and stays.
            if ($v >= 10_000_000_000) {
                return $this->id($v);
            }
            return $this->isIdKey($key) && $v >= 100 ? $this->id($v) : $v;
        }
        if (!is_string($v)) {
            return $v; // bool, null
        }
        return $this->scrubString($v, $key, $kind, $inChoices);
    }

    private function isIdKey(string $key): bool
    {
        // Real Freshservice puts ids under keys like members, observers, leaders, escalate_to, member_of, observer_of.
        return $key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_ids') || $key === 'ids'
            || in_array($key, ['members', 'observers', 'leaders', 'escalate_to'], true)
            || str_ends_with($key, '_of') || str_ends_with($key, '_pending_approval');
    }

    private function id(int|float $v): int
    {
        $k = 'id:' . $v;
        if ($v === 0) {
            return 0;
        }
        return $this->dict[$k] ??= 1000 + count(array_filter(array_keys($this->dict), static fn ($x) => str_starts_with($x, 'id:')));
    }

    private function scrubString(string $s, string $key, string $kind, bool $inChoices): string
    {
        if ($s === '') {
            return '';
        }
        // Dates and times: kept (the adapter parses them); they carry no name.
        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', $s)) {
            return $s;
        }
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $s)) {
            return $s; // time_spent "01:30"
        }
        if ($this->isIdKey($key) && ctype_digit($s)) {
            return (string) $this->id((int) $s);
        }
        if (in_array($key, self::KEEP_STRING_KEYS, true)) {
            return $s;
        }
        // The category choices are company vocabulary: replaced consistently, structure kept.
        if ($inChoices) {
            return $this->keepVocab ? $s : $this->label('Choice', $s);
        }
        // On the ticket form listing `name` identifies a FIELD (the adapter looks for "category"); a slug is kept.
        // The labels are the company's own wording and are replaced.
        if ($kind === 'form' && $key === 'name') {
            return preg_match('/^[a-z][a-z_0-9]*$/', $s) ? $s : $this->label('Field', $s);
        }
        if ($kind === 'form' && in_array($key, ['label', 'label_for_requesters'], true)) {
            return $this->label('Label', $s);
        }
        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $s)) {
            return 'user' . $this->n('mail', $s) . '@example.test';
        }
        if (preg_match('#^https?://#i', $s)) {
            return 'https://example.test/' . $this->n('url', $s) . (str_contains($s, '?') ? '?sig=x' : '');
        }
        // Ticket categories and the form's choices are ONE vocabulary: the same word gets the same placeholder.
        if (in_array($key, ['category', 'sub_category', 'item_category'], true)) {
            return $this->label('Choice', $s);
        }
        if (in_array($key, self::WORD_KEYS, true)) {
            return $this->wordsFor($key, $s);
        }
        // Anything unknown is replaced too: a custom field, a host name, an id string.
        return 'value-' . $this->n('val', $s);
    }

    private function wordsFor(string $key, string $s): string
    {
        $html = $s !== strip_tags($s);
        $n = $this->n('w:' . $key, $s);
        return match (true) {
            in_array($key, ['first_name', 'last_name', 'name', 'job_title', 'department', 'department_names', 'location_name'], true) => ucfirst(str_replace('_', ' ', $key)) . ' ' . $n,
            $html => '<div>Text ' . $n . '</div>',
            default => 'Text ' . $n,
        };
    }

    private function label(string $prefix, string $s): string
    {
        return $prefix . ' ' . $this->n('l:' . $prefix, $s);
    }

    private function n(string $kind, string $value): int
    {
        $k = $kind . '|' . $value;
        if (!isset($this->dict[$k])) {
            $this->counter[$kind] = ($this->counter[$kind] ?? 0) + 1;
            $this->dict[$k] = $this->counter[$kind];
        }
        return $this->dict[$k];
    }

    /** One-line summary of a scrubbed answer: top-level keys with list sizes. */
    public static function shape(array $d): string
    {
        $parts = [];
        foreach ($d as $k => $v) {
            if ($k === '_recorded') {
                continue;
            }
            $parts[] = $k . (is_array($v) ? (array_is_list($v) ? '[' . count($v) . ']' : '{' . count($v) . '}') : '');
        }
        return implode(' ', array_slice($parts, 0, 6));
    }
}
