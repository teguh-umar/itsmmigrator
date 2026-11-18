<?php

use GlpiPlugin\Itsmmigrator\RightDefinition;
use GlpiPlugin\Itsmmigrator\Source;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::CONFIG, READ);

$src = new Source();
$id  = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

if (isset($_POST['add'])) {
    $src->check(-1, CREATE, $_POST);
    $new = $src->add($_POST);
    Html::redirect($new ? Source::getFormURLWithID($new) : Source::getFormURL());
}

if (isset($_POST['update'])) {
    $src->check($id, UPDATE);
    $src->update($_POST);
    Html::back();
}

if (isset($_POST['purge'])) {
    $src->check($id, PURGE);
    $src->delete(['id' => $id], true);
    Html::redirect(Source::getSearchURL());
}

$menus = ['central' => [\GlpiPlugin\Pgnsuite\Menu::SECTOR, 'itsmmigrator', 'source'], 'helpdesk' => []];
Source::displayFullPageForItem($id, $menus, []);
