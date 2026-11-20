<?php

namespace GlpiPlugin\Itsmmigrator\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Itsmmigrator\AssetCsv;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates assets in GLPI from a CSV exported from another GLPI's asset list. Local only: no source API.
 * Names, serials and addresses are never printed: the output is counts and row numbers.
 *
 * An asset whose name already exists in the target entity (not in the trash) is left alone, so the command can be
 * run again. Type, model, manufacturer, location and status are found by name and created when missing.
 */
class ImportAssetsCommand extends AbstractCommand
{
    private const CORE = ['NetworkEquipment', 'Computer', 'Printer', 'Phone', 'Peripheral', 'Monitor'];

    protected function configure()
    {
        parent::configure();
        $this->setName('plugins:itsmmigrator:import-assets');
        $this->setDescription('Create assets (network devices and others) from a CSV exported from a GLPI asset list');
        $this->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'GLPI login to run as (required)');
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path of the CSV file (required; keep it out of the repository)');
        $this->addOption('itemtype', null, InputOption::VALUE_REQUIRED, 'NetworkEquipment (default), Computer, Printer, Phone, Peripheral, Monitor, or the system name of an Asset definition (e.g. Storage)', 'NetworkEquipment');
        $this->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Target entity id (required)');
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
        $file   = (string) $input->getOption('file');
        if ($entity <= 0 || countElementsInTable('glpi_entities', ['id' => $entity]) === 0) {
            $output->writeln('<error>--entity must be the id of an existing entity (not the root)</error>');
            return self::FAILURE;
        }
        if ($file === '' || !is_file($file)) {
            $output->writeln('<error>--file must be the path of an existing CSV file</error>');
            return self::FAILURE;
        }
        $class = $this->resolveClass((string) $input->getOption('itemtype'));
        if ($class === null) {
            $output->writeln('<error>--itemtype is not a supported type or a known Asset definition</error>');
            return self::FAILURE;
        }
        $dry    = (bool) $input->getOption('dry-run');
        $parsed = AssetCsv::parse((string) file_get_contents($file));
        if ($parsed['errors'] !== []) {
            $output->writeln('<error>' . implode('; ', $parsed['errors']) . '</error>');
            return self::FAILURE;
        }
        $output->writeln('Columns understood: ' . implode(', ', $parsed['columns']));
        $core   = in_array($class, array_map(fn ($c) => $c, self::CORE), true);
        $table  = getTableForItemType($class);
        $def    = is_a($class, \Glpi\Asset\Asset::class, true) ? $class::getDefinition()->getID() : 0;
        $counts = ['rows' => 0, 'created' => 0, 'exists' => 0, 'failed' => 0];
        $failed = [];
        foreach ($parsed['rows'] as $i => $row) {
            $counts['rows']++;
            $where = ['name' => $row['name'], 'entities_id' => $entity];
            if ($DB->fieldExists($table, 'is_deleted')) {
                $where['is_deleted'] = 0;
            }
            if ($def > 0) {
                $where['assets_assetdefinitions_id'] = $def;
            }
            if (countElementsInTable($table, $where) > 0) {
                $counts['exists']++;
                continue;
            }
            if ($dry) {
                $counts['created']++;
                continue;
            }
            $in = ['name' => $row['name'], 'entities_id' => $entity, 'is_recursive' => 0, 'serial' => $row['serial'], 'otherserial' => $row['inventory'], 'comment' => $row['comment']];
            $in['manufacturers_id'] = $row['manufacturer'] !== '' ? $this->dropdown('Manufacturer', $row['manufacturer'], $entity) : 0;
            $in['locations_id']     = $row['location'] !== '' ? $this->dropdown('Location', $row['location'], $entity) : 0;
            $in['states_id']        = $row['status'] !== '' ? $this->dropdown('State', $row['status'], $entity) : 0;
            if ($core) {
                foreach (['type' => 'Type', 'model' => 'Model'] as $key => $suffix) {
                    if ($row[$key] !== '') {
                        $in[getForeignKeyFieldForItemType($class . $suffix)] = $this->dropdown($class . $suffix, $row[$key], $entity);
                    }
                }
            }
            $new = (int) (new $class())->add($in);
            if ($new > 0) {
                $counts['created']++;
            } else {
                $counts['failed']++;
                $failed[] = $i + 2; // the line in the file (1 = header)
            }
        }
        foreach ($counts as $k => $n) {
            $output->writeln(sprintf('%-8s %d', $k, $n));
        }
        if ($failed !== []) {
            $output->writeln('Failed on file lines: ' . implode(', ', array_slice($failed, 0, 30)));
        }
        $output->writeln($dry ? 'Dry run: nothing written ("created" = would be created).' : 'Done.');
        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The id of a dropdown value by name: one that exists (visible in the entity first, then in any entity: GLPI's own
     * importExternal answers false for a value that exists but is not visible there, e.g. the default status "In use"),
     * else a new one. 0 when it cannot be had.
     */
    private function dropdown(string $class, string $value, int $entity): int
    {
        global $DB;
        $table = getTableForItemType($class);
        $by    = $DB->fieldExists($table, 'completename') ? 'completename' : 'name';
        $crit  = [$by => $value];
        if ($DB->fieldExists($table, 'entities_id')) {
            $seen = $crit + getEntitiesRestrictCriteria($table, 'entities_id', $entity, true);
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $seen, 'LIMIT' => 1]) as $r) {
                return (int) $r['id'];
            }
        }
        $id = \Dropdown::importExternal($class, $value, $entity);
        if (is_numeric($id) && (int) $id > 0) {
            return (int) $id;
        }
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => $crit, 'LIMIT' => 1]) as $r) {
            return (int) $r['id'];
        }
        return 0;
    }

    /** @return class-string<\CommonDBTM>|null */
    private function resolveClass(string $itemtype): ?string
    {
        foreach (self::CORE as $c) {
            if (strcasecmp($c, $itemtype) === 0) {
                return $c;
            }
        }
        $def = new \Glpi\Asset\AssetDefinition();
        if ($def->getFromDBBySystemName($itemtype)) {
            $cls = $def->getAssetClassName();
            return class_exists($cls) ? $cls : null;
        }
        return null;
    }
}
