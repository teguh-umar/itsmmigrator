<?php

use GlpiPlugin\Itsmmigrator\RightDefinition;
use GlpiPlugin\Itsmmigrator\Source;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::CONFIG, READ);

Html::header(...Source::getHeaderParameters());
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('itsmmigrator', 'source');
Search::show(Source::class);
Html::footer();
