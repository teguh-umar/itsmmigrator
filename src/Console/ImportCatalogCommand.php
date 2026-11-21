<?php

namespace GlpiPlugin\Itsmmigrator\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Itsmmigrator\Catalog\CatalogImporter;
use GlpiPlugin\Itsmmigrator\Source;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates GLPI Forms from a source's service catalog: one category tree, one Form per catalog item, its custom fields
 * as questions (see Catalog\CatalogImporter, which the Run page uses too). Every Form is created INACTIVE and NOT
 * flagged is_draft (GLPI hides such a form from the list and purges it), in the source's target entity, so nothing shows
 * in the service catalog until an administrator has reviewed it and switched it on. A question's "required" flag is kept;
 * it only applies when someone submits the form, never when an administrator edits or saves it.
 *
 * An item already imported (mapped) is left alone: running again adds nothing. Names are never printed: counts only.
 */
class ImportCatalogCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('plugins:itsmmigrator:import-catalog');
        $this->setDescription('Create inactive GLPI Forms from the service catalog of a source');
        $this->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'GLPI login to run as (required)');
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source id (required)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and count only, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->loadUserSession((string) $input->getOption('username'));
        } catch (\InvalidArgumentException) {
            $output->writeln('<error>--username is required: a GLPI login</error>');
            return self::FAILURE;
        }
        $src = new Source();
        if ((int) $input->getOption('source') <= 0 || !$src->getFromDB((int) $input->getOption('source'))) {
            $output->writeln('<error>--source must be the id of an existing source</error>');
            return self::FAILURE;
        }
        if ($src->apiKey() === '') {
            $output->writeln('<error>No API key is stored for this source: enter it in the source form</error>');
            return self::FAILURE;
        }
        $dry = (bool) $input->getOption('dry-run');
        try {
            $prep = CatalogImporter::prepare($src);
            $cat  = CatalogImporter::load($prep['token']);
            CatalogImporter::forget($prep['token']);
        } catch (\Throwable $e) {
            $output->writeln('<error>Reading the catalog failed: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
        $r = CatalogImporter::process($src, (array) $cat, $dry, null);
        if ($r['busy']) {
            $output->writeln('<error>Another catalog import of this source is running: try again when it ends</error>');
            return self::FAILURE;
        }
        foreach ($r['counts'] as $k => $v) {
            $output->writeln(sprintf('%-17s %d', $k, $v));
        }
        ksort($r['kinds']);
        $output->writeln('question kinds: ' . json_encode($r['kinds']));
        $output->writeln($dry ? 'Dry run: nothing written ("forms" = would be created).' : 'Done. Every Form is inactive: review it in Administration > Forms, then switch it on.');
        return $r['counts']['forms_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
