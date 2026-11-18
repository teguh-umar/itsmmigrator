<?php

namespace GlpiPlugin\Itsmmigrator\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Itsmmigrator\MapRepository;
use GlpiPlugin\Itsmmigrator\Run;
use GlpiPlugin\Itsmmigrator\Source;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `plugins:itsmmigrator:purge` (spec §10 called it `itsmmigrator:purge`).
 *
 * The ONLY way the plugin tables are ever dropped. Migrated GLPI data (users, groups, tickets...) is
 * never deleted. Without --confirm it only prints what it would do.
 */
class PurgeCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugins:itsmmigrator:purge');
        $this->setDescription('Drop the plugin tables, or only one source\'s map rows. Migrated GLPI data stays.');
        $this->addOption('confirm', null, InputOption::VALUE_NONE, 'Required to act: the next run will import everything again');
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Only delete this source\'s map rows (id)');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        global $DB;

        $tables = [Source::getTable(), MapRepository::table(), Run::getTable()];
        $only   = $input->getOption('source');

        if ($only !== null) {
            $sid   = (int) $only;
            $count = countElementsInTable(MapRepository::table(), ['sources_id' => $sid]);
            $output->writeln(sprintf('Source %d: %d map row(s). The next run of this source will import everything again.', $sid, $count));
            if (!$input->getOption('confirm')) {
                $output->writeln('<comment>Nothing deleted. Add --confirm to delete them.</comment>');
                return self::SUCCESS;
            }
            $DB->delete(MapRepository::table(), ['sources_id' => $sid]);
            $output->writeln('<info>Map rows deleted. Migrated GLPI data was not touched.</info>');
            return self::SUCCESS;
        }

        foreach ($tables as $t) {
            $output->writeln(sprintf('%s: %d row(s)', $t, $DB->tableExists($t) ? countElementsInTable($t) : 0));
        }
        $output->writeln('Dropping these tables means the next run imports everything again. Migrated GLPI data stays.');
        if (!$input->getOption('confirm')) {
            $output->writeln('<comment>Nothing dropped. Add --confirm to drop the tables.</comment>');
            return self::SUCCESS;
        }
        foreach ($tables as $t) {
            if ($DB->tableExists($t)) {
                $DB->doQuery('DROP TABLE `' . $t . '`');
            }
        }
        $output->writeln('<info>Tables dropped. Reinstall the plugin (plugin:install --force) to recreate them empty.</info>');
        return self::SUCCESS;
    }
}
