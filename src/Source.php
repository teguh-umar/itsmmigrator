<?php

namespace GlpiPlugin\Itsmmigrator;

use CommonDBTM;
use GLPIKey;
use Html;
use Session;

/**
 * One configured ITSM source, with ONE target entity (> 0, never recursive). The API key lives in
 * `api_key`, encrypted with GLPIKey. The form never renders it back; an empty value on update
 * keeps the stored one.
 */
class Source extends CommonDBTM
{
    public static $rightname = RightDefinition::CONFIG;

    public const ADAPTERS  = ['freshservice', 'sdp'];
    public const SCHEDULES = ['manual', 'weekly'];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_itsmmigrator_sources';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('ITSM source', 'ITSM sources', $nb, 'itsmmigrator');
    }

    public static function getIcon()
    {
        return 'ti ti-database-import';
    }

    /** Instance method on purpose: CommonDBTM declares it non-static. */
    public function isEntityAssign()
    {
        return true;
    }

    /** Never recursive: a source's target entity is isolated (spec §0 principle 5). */
    public function maybeRecursive()
    {
        return false;
    }

    /** Core's getHeaderParameters() composes [title, '', ...these]. */
    public static function getSectorizedDetails(): array
    {
        return [\GlpiPlugin\Pgnsuite\Menu::SECTOR, 'itsmmigrator', 'source'];
    }

    public function rawSearchOptions()
    {
        $t = self::getTable();
        $o = static fn (int $id, string $field, string $name, string $type) => ['id' => $id, 'table' => $t, 'field' => $field, 'name' => $name, 'datatype' => $type, 'massiveaction' => false];
        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            $o(1, 'name', __('Name'), 'itemlink'),
            $o(2, 'adapter', __('Adapter', 'itsmmigrator'), 'string'),
            $o(3, 'base_url', __('Base URL', 'itsmmigrator'), 'string'),
            $o(4, 'schedule', __('Schedule', 'itsmmigrator'), 'string'),
            $o(5, 'watermark', __('Watermark', 'itsmmigrator'), 'datetime'),
            $o(6, 'is_active', __('Active'), 'bool'),
            ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => \Entity::getTypeName(1), 'datatype' => 'dropdown', 'massiveaction' => false],
        ];
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(RightDefinition::CONFIG, READ);
    }

    public static function canCreate(): bool
    {
        return (bool) Session::haveRight(RightDefinition::CONFIG, UPDATE);
    }

    public static function canUpdate(): bool
    {
        return (bool) Session::haveRight(RightDefinition::CONFIG, UPDATE);
    }

    public static function canPurge(): bool
    {
        return (bool) Session::haveRight(RightDefinition::CONFIG, UPDATE);
    }

    /**
     * Validation errors for a posted source. Pure: no GLPI calls, so tests can assert on it.
     *
     * @param array<string,mixed> $input  posted fields (only keys present are checked)
     * @param array<string,mixed> $stored current row ([] on add)
     * @param string[] $otherActive names of the OTHER active sources that use the same adapter
     *                              (the maps key has no sources_id, spec §3: one active source per adapter)
     * @return string[]
     */
    public static function errors(array $input, array $stored, array $otherActive = []): array
    {
        $merged = array_merge($stored, $input);
        $add    = $stored === [];
        $errors = [];

        if ($add || array_key_exists('name', $input)) {
            if (trim((string) ($merged['name'] ?? '')) === '') {
                $errors[] = __('The name is required.', 'itsmmigrator');
            }
        }
        if ($add || array_key_exists('adapter', $input)) {
            if (!in_array((string) ($merged['adapter'] ?? ''), self::ADAPTERS, true)) {
                $errors[] = __('Choose an adapter.', 'itsmmigrator');
            }
        }
        if ($add || array_key_exists('entities_id', $input)) {
            // Nothing lands in the Root Entity (spec §0 principle 5).
            if ((int) ($merged['entities_id'] ?? 0) <= 0) {
                $errors[] = __('Choose a target entity below the root entity.', 'itsmmigrator');
            }
        }
        if ($add || array_key_exists('base_url', $input)) {
            // TLS is always verified: plain http is refused.
            if (!preg_match('#^https://[^\s/]+#i', trim((string) ($merged['base_url'] ?? '')))) {
                $errors[] = __('The base URL must start with https://.', 'itsmmigrator');
            }
        }
        // is_active defaults to 1 in the table, so an add without the field is active too.
        if ($otherActive !== [] && (int) ($merged['is_active'] ?? 1) === 1) {
            $errors[] = sprintf(
                __('Another active source already uses the %1$s adapter (%2$s). Deactivate it first: only one active source per adapter is supported.', 'itsmmigrator'),
                (string) ($merged['adapter'] ?? ''),
                implode(', ', $otherActive)
            );
        }
        // Import tickets updated since: a date (or an ISO time), checked before it is stored in the options.
        if (array_key_exists('import_since', $input) && trim((string) $input['import_since']) !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?Z?)?$/', trim((string) $input['import_since']))) {
            $errors[] = __('Import tickets updated since: use a date like 2026-01-01.', 'itsmmigrator');
        }
        if (array_key_exists('schedule', $input) &&!in_array((string) $input['schedule'], self::SCHEDULES, true)) {
            $errors[] = __('Unknown schedule.', 'itsmmigrator');
        }
        foreach (['status_map', 'priority_map', 'type_map', 'options'] as $json) {
            $v = trim((string) ($input[$json] ?? ''));
            if ($v !== '' && !is_array(json_decode($v, true))) {
                $errors[] = sprintf(__('%s is not valid JSON.', 'itsmmigrator'), $json);
            }
        }
        return $errors;
    }

    /**
     * Shapes a posted source for storage. Pure except for the injected encrypter, so tests can
     * pass a fake. An empty api_key keeps the stored one.
     *
     * @param array<string,mixed> $input
     * @param callable(string):string $encrypt
     * @return array<string,mixed>
     */
    public static function prepare(array $input, callable $encrypt, array $stored = []): array
    {
        // A source is never recursive, whatever the form posted.
        unset($input['is_recursive']);

        if (array_key_exists('api_key', $input)) {
            $key = (string) $input['api_key'];
            if ($key === '') {
                unset($input['api_key']);
            } else {
                $input['api_key'] = $encrypt($key);
            }
        }
        foreach (['status_map', 'priority_map', 'type_map', 'options'] as $json) {
            if (array_key_exists($json, $input) && trim((string) $input[$json]) === '') {
                $input[$json] = null;
            }
        }
        // Two settings live in the options JSON and have their own form fields: the category list (one path per line)
        // and the "import tickets updated since" date. Everything else in options is kept as it is.
        if (array_key_exists('ticket_categories_text', $input) || array_key_exists('import_since', $input)) {
            $options = json_decode((string) ($input['options'] ?? $stored['options'] ?? ''), true);
            $options = is_array($options) ? $options : [];
            if (array_key_exists('ticket_categories_text', $input)) {
                $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $input['ticket_categories_text']) ?: []), static fn (string $l): bool => $l !== ''));
                if ($lines === []) {
                    unset($options['ticket_categories']);
                } else {
                    $options['ticket_categories'] = $lines;
                }
                unset($input['ticket_categories_text']);
            }
            if (array_key_exists('import_since', $input)) {
                $d = trim((string) $input['import_since']);
                if ($d === '') {
                    unset($options['import_since']);
                } else {
                    $options['import_since'] = $d;
                }
                unset($input['import_since']);
            }
            $input['options'] = $options === [] ? null : json_encode($options, JSON_UNESCAPED_UNICODE);
        }        if (array_key_exists('base_url', $input)) {
            $input['base_url'] = rtrim(trim((string) $input['base_url']), '/');
        }
        return $input;
    }

    /** Shows each error and returns true when there is at least one. */
    private function refuse(array $errors): bool
    {
        foreach ($errors as $e) {
            Session::addMessageAfterRedirect(htmlescape($e), false, ERROR);
        }
        return $errors !== [];
    }

    private static function encrypter(): callable
    {
        return static fn (string $plain): string => (new GLPIKey())->encrypt($plain);
    }

    /**
     * Names of the other ACTIVE sources on this adapter (excluding $exceptId).
     *
     * @return string[]
     */
    private static function otherActiveOnAdapter(string $adapter, int $exceptId): array
    {
        global $DB;
        if ($adapter === '') {
            return [];
        }
        $names = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['adapter' => $adapter, 'is_active' => 1, ['NOT' => ['id' => $exceptId]]],
        ]) as $row) {
            $names[] = (string) $row['name'];
        }
        return $names;
    }

    public function prepareInputForAdd($input)
    {
        $others = self::otherActiveOnAdapter((string) ($input['adapter'] ?? ''), 0);
        return $this->refuse(self::errors($input, [], $others)) ? false : self::prepare($input, self::encrypter());
    }

    public function prepareInputForUpdate($input)
    {
        $adapter = (string) ($input['adapter'] ?? $this->fields['adapter'] ?? '');
        $others  = self::otherActiveOnAdapter($adapter, (int) ($this->fields['id'] ?? 0));
        return $this->refuse(self::errors($input, $this->fields, $others)) ? false : self::prepare($input, self::encrypter(), $this->fields);
    }

    /** The only place a stored key is decrypted. */
    public function apiKey(): string
    {
        $raw = (string) ($this->fields['api_key'] ?? '');
        return $raw === '' ? '' : (string) ((new GLPIKey())->decrypt($raw) ?? '');
    }

    public function showForm($ID, array $options = [])
    {
        $ID = (int) $ID;
        if ($ID > 0) {
            if (!$this->can($ID, READ)) {
                return false;
            }
            $f = $this->fields;
        } else {
            if (!static::canCreate()) {
                return false;
            }
            $f = ['name' => '', 'adapter' => 'freshservice', 'base_url' => '', 'api_key' => '', 'ca_bundle_path' => '',
                'entities_id' => 0, 'schedule' => 'manual', 'is_active' => 1];
        }
        $canedit = $ID > 0 ? $this->can($ID, UPDATE) : true;
        $dis     = $canedit ? '' : ' disabled';
        $has     = (string) $f['api_key'] !== '';

        echo "<div class='card'><div class='card-body'>";
        echo '<form method="post" action="' . htmlescape(static::getFormURL()) . '">';
        echo Html::hidden('id', ['value' => $ID]);

        echo '<div class="mb-2"><label class="form-label" for="itsmmigrator-src-name">' . __s('Name') . '</label>';
        echo '<input type="text" class="form-control" id="itsmmigrator-src-name" name="name" value="' . htmlescape((string) $f['name']) . '"' . $dis . '></div>';

        echo '<div class="mb-2"><label class="form-label" for="itsmmigrator-src-adapter">' . __s('Adapter', 'itsmmigrator') . '</label>';
        echo '<select class="form-select" id="itsmmigrator-src-adapter" name="adapter"' . $dis . '>';
        foreach (self::ADAPTERS as $a) {
            echo '<option value="' . htmlescape($a) . '"' . ($a === $f['adapter'] ? ' selected' : '') . '>' . htmlescape($a) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="mb-2"><label class="form-label" for="itsmmigrator-src-url">' . __s('Base URL', 'itsmmigrator') . '</label>';
        echo '<input type="text" class="form-control" id="itsmmigrator-src-url" name="base_url" placeholder="https://" value="' . htmlescape((string) $f['base_url']) . '"' . $dis . '></div>';

        // The stored key is never written into the page: no value attribute, only a placeholder.
        echo '<div class="mb-2"><label class="form-label" for="itsmmigrator-src-key">' . __s('API key', 'itsmmigrator') . '</label>';
        echo '<input type="password" autocomplete="new-password" class="form-control" id="itsmmigrator-src-key" name="api_key" placeholder="' . htmlescape($has ? __('Stored - leave blank to keep', 'itsmmigrator') : __('Not set', 'itsmmigrator')) . '"' . $dis . '></div>';

        echo '<div class="mb-2"><label class="form-label" for="itsmmigrator-src-ca">' . __s('CA bundle path (internal CA)', 'itsmmigrator') . '</label>';
        echo '<input type="text" class="form-control" id="itsmmigrator-src-ca" name="ca_bundle_path" value="' . htmlescape((string) $f['ca_bundle_path']) . '"' . $dis . '></div>';

        echo '<div class="mb-2"><label class="form-label">' . __s('Target entity', 'itsmmigrator') . '</label>';
        \Entity::dropdown(['name' => 'entities_id', 'value' => (int) $f['entities_id'], 'entity' => -1, 'readonly' => !$canedit, 'rand' => 'itsmsrc']);
        echo '</div>';

        $optsAll = json_decode((string) ($f['options'] ?? ''), true);
        $sinceVal = is_array($optsAll) ? (string) ($optsAll['import_since'] ?? '') : '';
        echo '<div class="mb-2" style="max-width:20rem"><label class="form-label" for="itsmmigrator-src-since">' . __s('Import tickets updated since', 'itsmmigrator') . '</label>';
        echo '<input type="date" class="form-control" id="itsmmigrator-src-since" name="import_since" value="' . htmlescape($sinceVal) . '"' . $dis . '>';
        echo '<div class="form-text">' . __s('Tickets last updated before this date are left out. To add an older period later, lower the date and run with "Read everything": tickets already imported are skipped.', 'itsmmigrator') . '</div></div>';
        $opts = json_decode((string) ($f['options'] ?? ''), true);
        $catsText = is_array($opts) && is_array($opts['ticket_categories'] ?? null) ? implode("\n", array_map('strval', $opts['ticket_categories'])) : '';
        echo '<div class="mb-2"><label class="form-label" for="itsmmigrator-src-cats">' . __s('Import only these ticket categories', 'itsmmigrator') . '</label>';
        echo '<textarea class="form-control" rows="3" id="itsmmigrator-src-cats" name="ticket_categories_text" placeholder="' . htmlescape(__('One category path per line, e.g. Hardware > Laptop. Empty = all categories.', 'itsmmigrator')) . '"' . $dis . '>' . htmlescape($catsText) . '</textarea>';
        echo '<div class="form-text">' . __s('A path includes everything below it. Tickets of other categories are left out (and are picked up later if you widen this list).', 'itsmmigrator') . '</div></div>';
        // Read-only picker: lists the source's category paths (one GET, nothing stored or logged) so the paths can be chosen, not typed.
        if ($ID > 0 && $canedit) {
            echo '<div class="mb-3" id="itsm-cat-picker">';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="itsm-cat-load"><i class="ti ti-list-tree me-1"></i>' . __s('Show the categories of this source', 'itsmmigrator') . '</button>';
            echo '<div id="itsm-cat-box" class="mt-2" style="display:none"><input type="search" class="form-control form-control-sm mb-2" id="itsm-cat-filter" placeholder="' . htmlescape(__('Filter...', 'itsmmigrator')) . '">';
            echo '<div id="itsm-cat-list" class="border rounded p-2" style="max-height:18rem;overflow:auto"></div>';
            echo '<div class="form-text">' . __s('Click a category to add it to the list above. Only paths are shown; nothing is saved until you press Save.', 'itsmmigrator') . '</div></div>';
            echo '<div id="itsm-cat-msg" class="form-text text-danger"></div></div>';
            echo Html::scriptBlock(self::pickerScript($ID));
        } elseif ($ID === 0) {
            echo '<div class="form-text mb-3">' . __s('Save the source first: then its categories can be listed here.', 'itsmmigrator') . '</div>';
        }
        echo '<div class="mb-3"><label class="form-label" for="itsmmigrator-src-schedule">' . __s('Schedule', 'itsmmigrator') . '</label>';
        echo '<select class="form-select" id="itsmmigrator-src-schedule" name="schedule"' . $dis . '>';
        foreach (self::SCHEDULES as $s) {
            echo '<option value="' . htmlescape($s) . '"' . ($s === $f['schedule'] ? ' selected' : '') . '>' . htmlescape($s) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="form-check mb-3"><input type="hidden" name="is_active" value="0">'
            . '<input class="form-check-input" type="checkbox" id="itsmmigrator-src-active" name="is_active" value="1"' . ((int) $f['is_active'] === 1 ? ' checked' : '') . $dis . '>'
            . '<label class="form-check-label" for="itsmmigrator-src-active">' . __s('Active') . '</label></div>';

        if ($canedit) {
            if ($ID > 0) {
                echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary me-2']);
                if (static::canPurge()) {
                    echo Html::submit(_sx('button', 'Delete permanently'), ['name' => 'purge', 'class' => 'btn btn-outline-danger', 'confirm' => __('Delete this source? Its mapping rows stay.', 'itsmmigrator')]);
                }
            } else {
                echo Html::submit(_sx('button', 'Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            }
        }
        Html::closeForm();
        echo '</div></div>';
        return true;
    }

    /** The picker's script: one POST to ajax/categories.php, then a filterable list; a click appends the path. */
    private static function pickerScript(int $id): string
    {
        $url = json_encode('/plugins/itsmmigrator/ajax/categories.php');
        $sid = (int) $id;
        return <<<JS
(function () {
    const btn = document.getElementById('itsm-cat-load'), box = document.getElementById('itsm-cat-box'), list = document.getElementById('itsm-cat-list'),
          filter = document.getElementById('itsm-cat-filter'), msg = document.getElementById('itsm-cat-msg'), area = document.getElementById('itsmmigrator-src-cats');
    if (!btn || !area) { return; }
    let paths = [];
    function token() { const m = document.querySelector('meta[property="glpi:csrf_token"]'); return m ? m.getAttribute('content') : ''; }
    function chosen() { return area.value.split(/\\r?\\n/).map(s => s.trim().toLowerCase()); }
    function render() {
        const q = filter.value.trim().toLowerCase(), have = chosen();
        list.textContent = '';
        paths.filter(p => !q || p.toLowerCase().includes(q)).slice(0, 400).forEach(p => {
            const depth = p.split(' > ').length - 1, row = document.createElement('div');
            row.style.cssText = 'cursor:pointer;padding:1px 0;padding-left:' + (depth * 1.1) + 'rem';
            row.textContent = (have.includes(p.toLowerCase()) ? '\\u2713 ' : '') + p.split(' > ').pop();
            row.title = p;
            row.addEventListener('click', () => { if (!have.includes(p.toLowerCase())) { area.value = (area.value.trim() ? area.value.replace(/\\s+$/, '') + '\\n' : '') + p; } render(); });
            list.appendChild(row);
        });
    }
    btn.addEventListener('click', function () {
        msg.textContent = ''; btn.disabled = true;
        fetch({$url}, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ sources_id: '{$sid}' }),
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': token() } })
            .then(r => r.text().then(t => { try { return JSON.parse(t); } catch (e) { throw new Error('HTTP ' + r.status + ': not JSON'); } }))
            .then(r => { if (!r.success) { throw new Error(r.message || 'failed'); } paths = r.paths || []; box.style.display = ''; render(); if (r.truncated) { msg.textContent = 'The list is long: only the first part is shown, use the filter.'; } })
            .catch(e => { msg.textContent = e.message; })
            .finally(() => { btn.disabled = false; });
    });
    filter.addEventListener('input', render);
})();
JS;
    }
}
