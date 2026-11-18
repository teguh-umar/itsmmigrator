<?php

// Minimal GLPI stand-ins for menu_test / config_test. No GLPI, no DB.
// Rights are driven by Session::$rights (right name => mask).

class Session
{
    public static array $rights = [];
    public static array $active = [1];
    /** entity id => its ancestors, as getAncestorsOf() would answer. */
    public static array $ancestors = [];
    public static function haveRight($name, $mask) { return ((self::$rights[$name] ?? 0) & $mask) === $mask; }
    public static function getPluralNumber() { return 2; }
    public static function getActiveEntities() { return self::$active; }
    public static function isCron() { return false; }
    /** Faithful to core: an ancestor is reachable only for a recursive item. See bootstrap.php. */
    public static function haveAccessToEntity($id, $rec = false)
    {
        return entity_access((int) $id, (bool) $rec, self::$active, self::$ancestors);
    }
}
if (!function_exists('isCommandLine')) {
    function isCommandLine() { return false; }
}
class CommonGLPI
{
    public static function getTypeName($nb = 0) { return 'x'; }
    public static function createTabEntry($t, $n = 0, $i = null, $icon = '') { return $t; }
}
class CommonDBTM extends CommonGLPI { public $fields = []; }
class Plugin
{
    /** class name => options, as plugin_init_itsmmigrator() registered it. */
    public static array $registered = [];
    public static function isPluginActive($p) { return true; }
    public static function registerClass($c, $o = []) { self::$registered[$c] = $o; }
}
class Config extends CommonGLPI
{
    public static array $store = [];
    public static function getConfigurationValues($ctx, array $keys = [])
    {
        $all = self::$store[$ctx] ?? [];
        return $keys === [] ? $all : array_intersect_key($all, array_flip($keys));
    }
    public static function setConfigurationValues($ctx, array $values)
    {
        self::$store[$ctx] = array_merge(self::$store[$ctx] ?? [], $values);
    }
    public static function getFormURL() { return '/front/config.form.php'; }
}
class Html
{
    public static function hidden($name, array $o = []) { return '<input type="hidden" name="' . $name . '" value="' . ($o['value'] ?? '') . '">'; }
    public static function submit($label, array $o = []) { return '<button type="submit" name="' . ($o['name'] ?? '') . '">' . $label . '</button>'; }
    public static function closeForm($display = true) { echo '</form>'; }
    public static function getPrefixedUrl($u) { return $u; }
}
