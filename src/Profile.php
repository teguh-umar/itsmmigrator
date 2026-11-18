<?php

namespace GlpiPlugin\Itsmmigrator;

use CommonGLPI;
use Html;

/**
 * "ITSM Migrator" rights tab on the core Setup > Profiles form. Registered with
 * Plugin::registerClass(Profile::class, ['addtabon' => 'Profile']). Saving posts to core's own
 * profile form, which already persists every right in ProfileRight::getAllPossibleRights().
 */
class Profile extends \Profile
{
    public static function getTypeName($nb = 0)
    {
        return __('ITSM Migrator', 'itsmmigrator');
    }

    public static function canView(): bool
    {
        return (bool) \Session::haveRight('profile', READ);
    }

    /** Instance method on purpose: core \Profile::getTabNameForItem() is non-static. */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        // $withtemplate reaches this as a STRING: compare through (int).
        if ($item instanceof \Profile && (int) $item->getID() > 0 && (int) $withtemplate === 0) {
            return self::createTabEntry(self::getTypeName(), 0, $item::class, 'ti ti-database-import');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Profile && (int) $item->getID() > 0) {
            (new self())->showRightsForm((int) $item->getID());
        }
        return true;
    }

    public function showRightsForm(int $profiles_id): void
    {
        $profile = new \Profile();
        if (!$profile->getFromDB($profiles_id)) {
            return;
        }

        $canedit = self::canUpdate();

        $rights = [];
        foreach (RightDefinition::names() as $name) {
            $rights[] = [
                'itemtype' => '',
                'rights'   => RightDefinition::rightsMatrix($name),
                'label'    => $name === RightDefinition::CONFIG ? __('Sources and settings', 'itsmmigrator') : __('Runs', 'itsmmigrator'),
                'field'    => $name,
            ];
        }

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlescape(\Profile::getFormURL()) . "' data-track-changes='true'>";
        }
        $profile->displayRightsChoiceMatrix($rights, [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => __('ITSM Migrator', 'itsmmigrator'),
        ]);
        if ($canedit) {
            echo "<div class='center mt-2'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";
    }
}
