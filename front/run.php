<?php

use GlpiPlugin\Itsmmigrator\Lock;
use GlpiPlugin\Itsmmigrator\RightDefinition;
use GlpiPlugin\Itsmmigrator\RunView;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::RUN, READ);

if (isset($_POST['release_stale'])) {
    Session::checkRight(RightDefinition::RUN, UPDATE);
    $n = Lock::releaseStale();
    Session::addMessageAfterRedirect(
        $n > 0 ? sprintf(__('%d stale run(s) released.', 'itsmmigrator'), $n) : __('Nothing to release: the run is still alive.', 'itsmmigrator'),
        false,
        $n > 0 ? INFO : WARNING
    );
    Html::redirect(RunView::pageUrl());
}

Html::header(__('ITSM Migrator runs', 'itsmmigrator'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'itsmmigrator', 'run');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('itsmmigrator', 'run');
RunView::render();
Html::footer();
