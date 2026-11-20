<?php

namespace GlpiPlugin\Itsmmigrator\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Itsmmigrator\Adapter\AdapterFactory;
use GlpiPlugin\Itsmmigrator\Engine;
use GlpiPlugin\Itsmmigrator\LockException;
use GlpiPlugin\Itsmmigrator\Matching\GlpiLdapLookup;
use GlpiPlugin\Itsmmigrator\Source;
use GlpiPlugin\Itsmmigrator\Writer\UserWriter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * GLPI 11 only loads plugin commands named `plugins:<key>:<name>`, so this is
 * `plugins:itsmmigrator:run` (spec §10 called it `itsmmigrator:run`).
 *
 * Run it as a real GLPI user: `--username=<login>` (required). Ticket rules and the entity checks need a
 * session, and the CLI has none of its own. The API key is never an option: it is read from the encrypted row.
 */
class RunCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugins:itsmmigrator:run');
        $this->setDescription('Import or sync one ITSM source into its target entity');

        $this->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'GLPI login to run as (required): rules and entity checks need a session');
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source id or name (required)');
        $this->addOption('category', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Import only tickets under this category path, e.g. "Hardware > Laptop" (repeatable; overrides the source option)');
        $this->addOption('modules', null, InputOption::VALUE_REQUIRED, 'Comma list of: ' . implode(',', Engine::MODULES) . ' (default: all supported)');
        $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'ISO8601 start for tickets; default: the source watermark minus 2 hours, or everything without one');
        $this->addOption('until', null, InputOption::VALUE_REQUIRED, 'End of the import range: a date (the whole day) or an ISO time. With --since it gives a from-to range');
        $this->addOption('date-basis', null, InputOption::VALUE_REQUIRED, 'Which date the range is about: updated (default, last update) or created (the day the ticket was opened)');
        $this->addOption('approvals', null, InputOption::VALUE_NONE, 'Also import the approvals of each ticket (one more request per ticket; the approver must already be a GLPI user)');
        $this->addOption('retry-skipped-attachments', null, InputOption::VALUE_NONE, 'Fetch again the attachments GLPI refused before (size or document type), now that its limits changed; only tickets that have one are read again');
        $this->addOption('full', null, InputOption::VALUE_NONE, 'Read every ticket: ignore the source watermark');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and match only, write nothing');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop each module after N items');
        $this->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Retry items whose mapping is failed');
        $this->addOption('recreate-missing', null, InputOption::VALUE_NONE, 'Recreate items whose GLPI record is gone');
        $this->addOption('force-unlock', null, InputOption::VALUE_NONE, 'Release a STALE lock (heartbeat older than 15 min)');
        $this->addOption('create-missing-users', null, InputOption::VALUE_NONE, 'Create local users that are not in AD');
        $this->addOption('activate-created-users', null, InputOption::VALUE_NONE, 'Activate the users created above');
        $this->addOption('fallback-user', null, InputOption::VALUE_REQUIRED, 'GLPI user id used when a source user cannot be matched');
        $this->addOption('assign-profiles', null, InputOption::VALUE_REQUIRED, 'none|requester_only|all (overrides the source option)');
        $this->addOption('no-ldap-lookup', null, InputOption::VALUE_NONE, 'Skip the LDAP lookup in user matching');
        // Planned: --full, --reconcile, --create-missing-assets, --include-drafts (kb/assets/SDP, M4+).
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $username = $input->getOption('username');
        if ($username === null || $username === '') {
            $output->writeln('<error>--username is required: the GLPI login to run as (its profile decides which entities it can write to)</error>');
            return self::FAILURE;
        }
        try {
            $this->loadUserSession((string) $username);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>No GLPI user named that</error>');
            return self::FAILURE;
        }

        $ref = $input->getOption('source');
        if ($ref === null || $ref === '') {
            $output->writeln('<error>--source is required (id or name)</error>');
            return self::FAILURE;
        }
        $basis = $input->getOption('date-basis');
        if ($basis !== null && !in_array($basis, ['updated', 'created'], true)) {
            $output->writeln('<error>--date-basis must be updated or created</error>');
            return self::FAILURE;
        }
        foreach (['since', 'until'] as $opt) {
            $v = $input->getOption($opt);
            if ($v !== null && $v !== '' && \GlpiPlugin\Itsmmigrator\DateRange::parse($v, false) === null) {
                $output->writeln("<error>--$opt: cannot read that date (use 2026-01-31 or an ISO time)</error>");
                return self::FAILURE;
            }
        }
        $profiles = $input->getOption('assign-profiles');
        if ($profiles !== null && !in_array($profiles, ['none', 'requester_only', 'all'], true)) {
            $output->writeln('<error>--assign-profiles must be none, requester_only or all</error>');
            return self::FAILURE;
        }

        $src = $this->findSource((string) $ref);
        if ($src === null) {
            $output->writeln("<error>No source matches '$ref'</error>");
            return self::FAILURE;
        }
        if ((int) $src->fields['is_active'] !== 1) {
            $output->writeln('<error>The source is not active</error>');
            return self::FAILURE;
        }

        try {
            $adapter = AdapterFactory::make($src->fields, $src->apiKey());
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
        if ($src->apiKey() === '') {
            $output->writeln('<error>No API key is stored for this source: enter it in the source form</error>');
            return self::FAILURE;
        }
        $test = $adapter->testConnection();
        if (!$test['ok']) {
            $output->writeln('<error>Connection test failed: ' . $test['message'] . '</error>');
            return self::FAILURE;
        }

        $flags = [
            'dry_run'                => (bool) $input->getOption('dry-run'),
            'full'                   => (bool) $input->getOption('full'),
            'approvals'              => $input->getOption('approvals') ? true : null,
            'retry_attachments'      => $input->getOption('retry-skipped-attachments') ? true : null,
            'retry_failed'           => (bool) $input->getOption('retry-failed'),
            'recreate_missing'       => (bool) $input->getOption('recreate-missing'),
            'force_unlock'           => (bool) $input->getOption('force-unlock'),
            'create_missing_users'   => (bool) $input->getOption('create-missing-users'),
            'activate_created_users' => (bool) $input->getOption('activate-created-users'),
            'ldap_lookup'            => !$input->getOption('no-ldap-lookup'),
            'fallback_user'          => (int) ($input->getOption('fallback-user') ?? 0),
            'assign_profiles'        => $profiles,
            'limit'                  => (int) ($input->getOption('limit') ?? 0),
            'since'                  => $input->getOption('since'),
            'until'                  => $input->getOption('until'),
            'date_basis'             => $basis,
            'categories'             => (array) $input->getOption('category'),
        ];
        $modules = $input->getOption('modules') !== null ? explode(',', (string) $input->getOption('modules')) : [];

        $fields = $src->fields;
        // The key never travels in the context: only the adapter holds it.
        unset($fields['api_key']);

        $log = static function (string $m) use ($output): void {
            $output->writeln(sprintf('[%s] %s', date('H:i:s'), $m));
        };

        try {
            $engine = new Engine($adapter, new UserWriter(new GlpiLdapLookup($log)));
            $res = $engine->run($fields, 'cli', $modules, $flags, $log);
        } catch (LockException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }

        $table = new Table($output);
        $table->setHeaders(array_merge(['module'], \GlpiPlugin\Itsmmigrator\Counters::KEYS));
        foreach ($res['counters'] as $module => $row) {
            $table->addRow(array_merge([$module], array_values($row)));
        }
        $table->render();
        foreach ((array) ($res['details'] ?? []) as $module => $row) {
            $output->writeln(sprintf('%s detail: %s', $module, implode(', ', array_map(static fn ($k, $n): string => "$k $n", array_keys($row), array_values($row)))));
        }
        $output->writeln(sprintf('Run %d: %s', $res['run_id'], $res['status']));

        return $res['status'] === 'success' ? self::SUCCESS : self::FAILURE;
    }

    private function findSource(string $ref): ?Source
    {
        global $DB;
        $where = ctype_digit($ref) ? ['id' => (int) $ref] : ['name' => $ref];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Source::getTable(), 'WHERE' => $where, 'LIMIT' => 1]) as $row) {
            $s = new Source();
            return $s->getFromDB((int) $row['id']) ? $s : null;
        }
        return null;
    }
}
