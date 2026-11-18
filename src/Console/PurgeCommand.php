<?php

namespace GlpiPlugin\Itsmmigrator\Console;

use Glpi\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `plugins:itsmmigrator:purge` (spec §10 called it `itsmmigrator:purge`). The only way the plugin
 * tables are ever dropped. Not built yet: it refuses, so nothing can be dropped by accident.
 */
class PurgeCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugins:itsmmigrator:purge');
        $this->setDescription('Drop the plugin tables, or one source\'s map rows (not built yet)');
        $this->addOption('confirm', null, InputOption::VALUE_NONE, 'Required: the next run will re-import everything');
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Only this source\'s map rows');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<error>plugins:itsmmigrator:purge: not built yet (planned for M2). Nothing was dropped.</error>');
        return self::FAILURE;
    }
}
