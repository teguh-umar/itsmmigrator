<?php

use GlpiPlugin\Itsmmigrator\RightDefinition;
use GlpiPlugin\Itsmmigrator\ReviewView;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::RUN, READ);

$result = ReviewView::handlePost();
if ($result !== null) {
    Session::addMessageAfterRedirect(htmlescape($result['message']), false, $result['ok'] ? INFO : ERROR);
    Html::redirect(ReviewView::pageUrl());
}

Html::header(__('Needs review', 'itsmmigrator'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'itsmmigrator', 'review');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('itsmmigrator', 'review');
ReviewView::render();
Html::footer();
