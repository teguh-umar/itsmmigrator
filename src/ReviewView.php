<?php

namespace GlpiPlugin\Itsmmigrator;

use Html;
use Session;

/**
 * The Needs review page: source users that could not be matched without guessing. The admin picks the
 * right GLPI user (a candidate, or any user), or says there is none. Only the mapping is written;
 * the picked user row is never touched.
 */
final class ReviewView
{
    public static function pageUrl(): string
    {
        return '/plugins/itsmmigrator/front/review.php';
    }

    /** Handle the POST of one row. @return array{ok: bool, message: string}|null null when nothing was posted */
    public static function handlePost(): ?array
    {
        if (!isset($_POST['resolve'])) {
            return null;
        }
        Session::checkRight(RightDefinition::RUN, UPDATE);
        $mapId = (int) ($_POST['map_id'] ?? 0);
        // "Another user" (the dropdown) wins over the candidate list; "none" is 0.
        $other = (int) ($_POST['users_id_other'] ?? 0);
        $pick  = (string) ($_POST['users_id_candidate'] ?? '');
        $users = $other > 0 ? $other : ($pick === 'none' ? 0 : (int) $pick);
        if ($other === 0 && $pick === '') {
            return ['ok' => false, 'message' => __('Pick a user, or "no GLPI user".', 'itsmmigrator')];
        }
        return NeedsReview::resolve($mapId, $users);
    }

    public static function render(): void
    {
        global $DB;
        $rows = NeedsReview::open();
        if ($rows === []) {
            echo '<div class="alert alert-success">' . htmlescape(__('Nothing needs review.', 'itsmmigrator')) . '</div>';
            return;
        }
        $names = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => Source::getTable()]) as $s) {
            $names[(int) $s['id']] = $s['name'];
        }
        $canEdit = Session::haveRight(RightDefinition::RUN, UPDATE);

        echo '<p class="text-muted">' . htmlescape(__('These source users share an e-mail with several GLPI users, or their e-mail and login point to different GLPI users. Nothing is guessed: until you decide, their tickets use the fallback user.', 'itsmmigrator')) . '</p>';
        echo '<div class="table-responsive"><table class="table table-hover card-table"><thead><tr>';
        foreach ([__('Source', 'itsmmigrator'), __('Id in the source', 'itsmmigrator'), __('GLPI candidates', 'itsmmigrator'), __('Decision', 'itsmmigrator')] as $h) {
            echo '<th>' . htmlescape($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $cands = NeedsReview::candidates((string) $r['error']);
            echo '<tr><td>' . htmlescape((string) ($names[(int) $r['sources_id']] ?? '#' . $r['sources_id'])) . '</td>';
            echo '<td>' . htmlescape((string) ($r['display_id'] ?? $r['src_id'])) . '</td>';
            echo '<td>';
            foreach ($cands as $uid) {
                echo '<div>#' . (int) $uid . ' ' . htmlescape(self::userLabel($uid)) . '</div>';
            }
            echo '</td><td>';
            if ($canEdit) {
                echo '<form method="post" action="' . htmlescape(self::pageUrl()) . '" class="d-flex gap-2 align-items-center">';
                echo Html::hidden('map_id', ['value' => (int) $r['id']]);
                echo '<select class="form-select form-select-sm" name="users_id_candidate" style="width:auto"><option value="">' . htmlescape(__('Choose')) . '</option>';
                foreach ($cands as $uid) {
                    echo '<option value="' . (int) $uid . '">#' . (int) $uid . ' ' . htmlescape(self::userLabel($uid)) . '</option>';
                }
                echo '<option value="none">' . htmlescape(__('No GLPI user', 'itsmmigrator')) . '</option></select>';
                \User::dropdown(['name' => 'users_id_other', 'value' => 0, 'right' => 'all', 'display_emptychoice' => true, 'rand' => 'rv' . (int) $r['id']]);
                echo Html::submit(__('Save'), ['name' => 'resolve', 'class' => 'btn btn-sm btn-primary']);
                Html::closeForm();
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Login and real name only: never an e-mail address. */
    private static function userLabel(int $id): string
    {
        $u = new \User();
        if (!$u->getFromDB($id)) {
            return '(missing)';
        }
        return trim((string) $u->fields['name'] . ' ' . ((string) ($u->fields['realname'] ?? '') !== '' ? '- ' . $u->fields['realname'] : ''));
    }
}
