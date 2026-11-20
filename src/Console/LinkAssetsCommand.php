<?php

namespace GlpiPlugin\Itsmmigrator\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Itsmmigrator\AssetLinker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Links the tickets already imported from one source to their monitor asset (the importer does it for new ones).
 * Local only: no request to the source. Counts only in its output.
 */
class LinkAssetsCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('plugins:itsmmigrator:link-assets');
        $this->setDescription('Link imported monitor (NMS) tickets to the GLPI asset named in them, or leave a private note');
        $this->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'GLPI login to run as (required)');
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source id (required)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count only, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        global $DB;
        try {
            $this->loadUserSession((string) $input->getOption('username'));
        } catch (\InvalidArgumentException) {
            $output->writeln('<error>--username is required: a GLPI login</error>');
            return self::FAILURE;
        }
        $sid = (int) $input->getOption('source');
        if ($sid <= 0) {
            $output->writeln('<error>--source is required (a source id)</error>');
            return self::FAILURE;
        }
        $dry    = (bool) $input->getOption('dry-run');
        $counts = [];
        foreach ($DB->request(['SELECT' => ['items_id'], 'FROM' => 'glpi_plugin_itsmmigrator_maps', 'WHERE' => ['sources_id' => $sid, 'src_type' => 'ticket', 'itemtype' => 'Ticket', 'status' => 'ok']]) as $m) {
            $r = AssetLinker::apply((int) $m['items_id'], $dry);
            $counts[$r] = ($counts[$r] ?? 0) + 1;
        }
        ksort($counts);
        foreach ($counts as $k => $n) {
            $output->writeln(sprintf('%-10s %d', $k, $n));
        }
        $output->writeln($dry ? 'Dry run: nothing written.' : 'Done.');
        return self::SUCCESS;
    }
}
