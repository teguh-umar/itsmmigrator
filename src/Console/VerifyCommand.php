<?php

namespace GlpiPlugin\Itsmmigrator\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Itsmmigrator\Verifier;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `plugins:itsmmigrator:verify` (spec §10 called it `itsmmigrator:verify`). Read-only: it never
 * writes. Exit code 0 when everything is clean, 1 when anything is found (the gate before production).
 */
class VerifyCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugins:itsmmigrator:verify');
        $this->setDescription('Read-only consistency check of everything the plugin migrated; exit 1 when anything is found');
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Only this source (id)');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $only   = $input->getOption('source');
        $result = (new Verifier())->run($only === null ? null : (int) $only);

        foreach ($result as $r) {
            if ($r['total'] === 0) {
                $output->writeln(sprintf('<info>OK</info>    %s', $r['title']));
                continue;
            }
            $output->writeln(sprintf('<error>FOUND</error> %s: %d', $r['title'], $r['total']));
            foreach ($r['findings'] as $f) {
                $output->writeln('         - ' . $f);
            }
            if ($r['total'] > count($r['findings'])) {
                $output->writeln(sprintf('         ... and %d more', $r['total'] - count($r['findings'])));
            }
        }

        $total = Verifier::total($result);
        $output->writeln($total === 0 ? '<info>Verify: clean</info>' : sprintf('<error>Verify: %d finding(s)</error>', $total));
        return $total === 0 ? self::SUCCESS : self::FAILURE;
    }
}
