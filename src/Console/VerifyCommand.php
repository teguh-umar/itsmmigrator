<?php

namespace GlpiPlugin\Itsmmigrator\Console;

use Glpi\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** `plugins:itsmmigrator:verify` (spec §10 called it `itsmmigrator:verify`). Read-only; checks arrive in M7. */
class VerifyCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugins:itsmmigrator:verify');
        $this->setDescription('Read-only consistency check of migrated data (checks not built yet)');
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source id or name');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<error>plugins:itsmmigrator:verify: the checks are not built yet (planned for M7). Nothing was verified.</error>');
        return self::FAILURE;
    }
}
