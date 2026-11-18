<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/glpi_stubs.php';
foreach (['CategoryFilter', 'RightDefinition', 'Source'] as $f) {
    require_once ITSM_ROOT . "/src/$f.php";
}

use GlpiPlugin\Itsmmigrator\CategoryFilter as F;
use GlpiPlugin\Itsmmigrator\Source;

// ---- parsing
check('empty / null filter = no filter', F::parse(null) === [] && F::parse('') === [] && F::parse(" \n \n") === []);
check('text: one path per line, levels split on ">", case and spacing ignored', F::parse("Hardware > Laptop\n  SOFTWARE ") === [['hardware', 'laptop'], ['software']]);
check('a comma is part of a name, not a separator', F::parse('Network, WAN') === [['network, wan']]);
check('an array value works too', F::parse(['A > B', ' ', 'C']) === [['a', 'b'], ['c']]);

// ---- tickets
$f = F::parse("Hardware\nSoftware > Office");
check('no filter allows every ticket, even without a category', F::allowsTicket([], []) && F::allowsTicket([], ['X']));
check('a top-level filter includes everything below it', F::allowsTicket($f, ['Hardware']) && F::allowsTicket($f, ['hardware', 'Laptop', 'Battery']));
check('a deeper filter includes only that branch', F::allowsTicket($f, ['Software', 'Office', 'Word']) && !F::allowsTicket($f, ['Software', 'Browser']) && !F::allowsTicket($f, ['Software']));
check('a ticket of another category is left out', !F::allowsTicket($f, ['Network']) && !F::allowsTicket($f, ['Hardwarex']));
check('with a filter, a ticket with NO category is left out', !F::allowsTicket($f, []));
check('matching ignores case and extra spaces', F::allowsTicket($f, ['  HARDWARE ', 'laptop']));

// ---- the category tree: needed = under a filter, or a parent of one
check('categories: the chosen branch and its children are needed', F::allowsCategory($f, ['Hardware']) && F::allowsCategory($f, ['Hardware', 'Laptop']));
check('categories: a parent of a deeper filter is needed ("Software" for "Software > Office")', F::allowsCategory($f, ['Software']) && F::allowsCategory($f, ['Software', 'Office', 'Word']));
check('categories: other branches are not created', !F::allowsCategory($f, ['Network']) && !F::allowsCategory($f, ['Software', 'Browser']));
check('categories: no filter creates all', F::allowsCategory([], ['Anything']));

// ---- the source form keeps the list in the options JSON
$enc = static fn (string $s): string => $s;
$out = Source::prepare(['ticket_categories_text' => "Hardware > Laptop\n\n Software "], $enc);
$opts = json_decode((string) $out['options'], true);
check('form: lines become options.ticket_categories, blank lines dropped', $opts['ticket_categories'] === ['Hardware > Laptop', 'Software'] && !array_key_exists('ticket_categories_text', $out));
$out2 = Source::prepare(['ticket_categories_text' => ''], $enc, ['options' => json_encode(['ticket_categories' => ['X'], 'email_aliases' => [['a', 'b']]])]);
$o2 = json_decode((string) $out2['options'], true);
check('form: emptying the box removes the filter but keeps the other options', !isset($o2['ticket_categories']) && $o2['email_aliases'] === [['a', 'b']]);
$out3 = Source::prepare(['ticket_categories_text' => 'A'], $enc, ['options' => json_encode(['email_aliases' => [['a', 'b']]])]);
check('form: adding a filter keeps the other options', json_decode((string) $out3['options'], true) === ['email_aliases' => [['a', 'b']], 'ticket_categories' => ['A']]);
check('form: with no categories box posted nothing about options changes', !array_key_exists('options', Source::prepare(['name' => 'x'], $enc)));

// ---- wiring, checked in the source
$tw = (string) file_get_contents(ITSM_ROOT . '/src/Writer/TicketWriter.php');
check('TicketWriter skips a filtered ticket BEFORE any map lookup or write (no map row, so widening later works)', strpos($tw, 'CategoryFilter::allowsTicket') < strpos($tw, 'MapRepository::find'));
$cw = (string) file_get_contents(ITSM_ROOT . '/src/Writer/CategoryWriter.php');
check('CategoryWriter honours the filter', str_contains($cw, 'CategoryFilter::allowsCategory'));
$en = (string) file_get_contents(ITSM_ROOT . '/src/Engine.php');
check('a filtered run never moves the watermark', str_contains($en, "!empty(\$flags['category_filter'])"));

done();
