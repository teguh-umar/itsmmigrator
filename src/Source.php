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
    public static function prepare(array $input, callable $encrypt): array
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
        if (array_key_exists('base_url', $input)) {
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
        return $this->refuse(self::errors($input, $this->fields, $others)) ? false : self::prepare($input, self::encrypter());
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
}
