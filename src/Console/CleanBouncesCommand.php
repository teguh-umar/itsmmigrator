<?php

namespace GlpiPlugin\Itsmmigrator\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Itsmmigrator\BounceCleaner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cleans the mail-bounce notices stored in the body of tickets and notes ALREADY imported into one entity (the importer
 * cleans new ones by itself). Nothing is deleted: the ticket or note stays, its body is shortened (see BounceCleaner).
 * Local only. Counts only in its output. A real run needs --backup: a JSON file with the original bodies, kept OUTSIDE the
 * repository (it holds real names and addresses).
 */
class CleanBouncesCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('plugins:itsmmigrator:clean-bounces');
        $this->setDescription('Shorten mail-bounce notices in imported tickets and notes (nothing is deleted)');
        $this->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'GLPI login to run as (required)');
        $this->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Entity id of the imported tickets (required)');
        $this->addOption('backup', null, InputOption::VALUE_REQUIRED, 'JSON file that receives the original bodies (required unless --dry-run; keep it out of the repository)');
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
        $entity = (int) $input->getOption('entity');
        if ($entity <= 0 || countElementsInTable('glpi_entities', ['id' => $entity]) === 0) {
            $output->writeln('<error>--entity is required (an existing entity id other than the root)</error>');
            return self::FAILURE;
        }
        $dry    = (bool) $input->getOption('dry-run');
        $backup = (string) $input->getOption('backup');
        if (!$dry && $backup === '') {
            $output->writeln('<error>--backup is required for a real run (a JSON file outside the repository), or use --dry-run</error>');
            return self::FAILURE;
        }

        $todo = [];
        foreach ($DB->request(['SELECT' => ['id', 'content'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $entity, ['content' => ['LIKE', '%headers%']]]]) as $r) {
            $new = BounceCleaner::clean((string) $r['content']);
            if ($new !== null) {
                $todo[] = ['glpi_tickets', (int) $r['id'], (string) $r['content'], $new];
            }
        }
        $sql = $DB->request(['SELECT' => ['f.id', 'f.content'], 'FROM' => 'glpi_itilfollowups AS f', 'INNER JOIN' => ['glpi_tickets AS t' => ['ON' => ['f' => 'items_id', 't' => 'id', ['AND' => ['f.itemtype' => 'Ticket']]]]], 'WHERE' => ['t.entities_id' => $entity, ['f.content' => ['LIKE', '%headers%']]]]);
        foreach ($sql as $r) {
            $new = BounceCleaner::clean((string) $r['content']);
            if ($new !== null) {
                $todo[] = ['glpi_itilfollowups', (int) $r['id'], (string) $r['content'], $new];
            }
        }

        $counts = ['tickets' => 0, 'notes' => 0];
        $before = 0;
        $after  = 0;
        foreach ($todo as [$table, , $old, $new]) {
            $counts[$table === 'glpi_tickets' ? 'tickets' : 'notes']++;
            $before += strlen($old);
            $after  += strlen($new);
        }
        if (!$dry && $todo !== []) {
            $orig = [];
            foreach ($todo as [$table, $id, $old]) {
                $orig[$table][$id] = $old;
            }
            if (file_put_contents($backup, json_encode($orig, JSON_UNESCAPED_UNICODE)) === false) {
                $output->writeln('<error>The backup file could not be written: nothing was changed</error>');
                return self::FAILURE;
            }
            foreach ($todo as [$table, $id, , $new]) {
                $DB->update($table, ['content' => $new], ['id' => $id]);
            }
        }
        $output->writeln(sprintf('tickets %d', $counts['tickets']));
        $output->writeln(sprintf('notes   %d', $counts['notes']));
        $output->writeln(sprintf('bytes   %d -> %d', $before, $after));
        $output->writeln($dry ? 'Dry run: nothing written.' : 'Done. Originals saved to the backup file.');
        return self::SUCCESS;
    }
}
