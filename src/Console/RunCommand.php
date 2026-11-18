<?php

namespace GlpiPlugin\Itsmmigrator\Console;

use Glpi\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * GLPI 11 only loads plugin commands named `plugins:<key>:<name>`, so this is
 * `plugins:itsmmigrator:run` (spec §10 called it `itsmmigrator:run`).
 * The engine arrives in M2; this build only declares the options and refuses to run.
 */
class RunCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugins:itsmmigrator:run');
        $this->setDescription('Run an import or sync for one ITSM source (engine not built yet)');

        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source id or name');
        $this->addOption('modules', null, InputOption::VALUE_REQUIRED, 'users,groups,categories,kb,assets,tickets,problems,changes');
        $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'ISO8601 start; default: source watermark');
        $this->addOption('full', null, InputOption::VALUE_NONE, 'Ignore the watermark');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and match only, write nothing');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after N items per module');
        $this->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Retry items whose mapping is failed');
        $this->addOption('recreate-missing', null, InputOption::VALUE_NONE, 'Recreate items whose GLPI record is gone');
        $this->addOption('reconcile', null, InputOption::VALUE_NONE, 'Compare source ids with the map');
        $this->addOption('force-unlock', null, InputOption::VALUE_NONE, 'Release a stale lock (heartbeat older than 15 min)');
        $this->addOption('create-missing-users', null, InputOption::VALUE_NONE, 'Create local users that are not in AD');
        $this->addOption('activate-created-users', null, InputOption::VALUE_NONE, 'Activate the users created above');
        $this->addOption('create-missing-assets', null, InputOption::VALUE_NONE, 'Create assets that match nothing');
        $this->addOption('include-drafts', null, InputOption::VALUE_NONE, 'Include draft items');
        $this->addOption('fallback-user', null, InputOption::VALUE_REQUIRED, 'GLPI user id used when a user cannot be matched');
        $this->addOption('assign-profiles', null, InputOption::VALUE_REQUIRED, 'none|requester_only|all');
        $this->addOption('no-ldap-lookup', null, InputOption::VALUE_NONE, 'Skip the LDAP lookup in user matching');
        // There is deliberately no API key option: the key comes from the encrypted source row.
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<error>plugins:itsmmigrator:run: the engine is not built yet (planned for M2). Nothing was done.</error>');
        return self::FAILURE;
    }
}
