<?php

namespace GlpiPlugin\Itsmmigrator\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Itsmmigrator\Adapter\AdapterFactory;
use GlpiPlugin\Itsmmigrator\Catalog\FormMapper;
use GlpiPlugin\Itsmmigrator\MapRepository;
use GlpiPlugin\Itsmmigrator\Source;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates GLPI Forms from a source's service catalog: one category tree, one Form per catalog item, its custom fields
 * as questions. Every Form is created INACTIVE and as a DRAFT, in the source's target entity, so nothing shows in the
 * service catalog until an administrator has reviewed it and switched it on. A question's "required" flag is kept; it
 * only applies when someone submits the form, never when an administrator edits or saves it.
 *
 * An item already imported (mapped) is left alone: running again adds nothing. Names are never printed: counts only.
 */
class ImportCatalogCommand extends AbstractCommand
{
    private const NS = 'Glpi\\Form\\QuestionType\\';

    protected function configure()
    {
        parent::configure();
        $this->setName('plugins:itsmmigrator:import-catalog');
        $this->setDescription('Create inactive draft GLPI Forms from the service catalog of a source');
        $this->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'GLPI login to run as (required)');
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source id (required)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and count only, write nothing');
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
        $src = new Source();
        if ((int) $input->getOption('source') <= 0 || !$src->getFromDB((int) $input->getOption('source'))) {
            $output->writeln('<error>--source must be the id of an existing source</error>');
            return self::FAILURE;
        }
        if ($src->apiKey() === '') {
            $output->writeln('<error>No API key is stored for this source: enter it in the source form</error>');
            return self::FAILURE;
        }
        $dry    = (bool) $input->getOption('dry-run');
        $sid    = (int) $src->getID();
        $source = (string) $src->fields['adapter'];
        $entity = (int) $src->fields['entities_id'];
        try {
            $cat = AdapterFactory::make($src->fields, $src->apiKey())->fetchServiceCatalog();
        } catch (\Throwable $e) {
            $output->writeln('<error>Reading the catalog failed: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
        $n = ['categories' => 0, 'categories_exist' => 0, 'forms' => 0, 'forms_exist' => 0, 'forms_failed' => 0, 'questions' => 0];
        $kinds = [];

        // ---- categories, parents first
        $byId = [];
        foreach ($cat['categories'] as $c) {
            $byId[(string) $c['id']] = $c;
        }
        $catId = [];
        $makeCat = function (string $id) use (&$makeCat, &$catId, &$byId, &$n, $sid, $source, $dry): int {
            if (isset($catId[$id])) {
                return $catId[$id];
            }
            $row = MapRepository::find($source, 'catalog_category', $id);
            if ($row !== null && (string) $row['status'] === MapRepository::OK && (int) $row['items_id'] > 0) {
                $n['categories_exist']++;
                return $catId[$id] = (int) $row['items_id'];
            }
            $c      = $byId[$id] ?? null;
            $parent = ($c !== null && !empty($c['parent_id']) && isset($byId[(string) $c['parent_id']])) ? $makeCat((string) $c['parent_id']) : 0;
            if ($c === null || $dry) {
                $n['categories']++;
                return $catId[$id] = 0;
            }
            $name  = mb_substr(trim((string) $c['name']) !== '' ? trim((string) $c['name']) : 'Category', 0, 255);
            $found = 0;
            foreach ($GLOBALS['DB']->request(['SELECT' => ['id'], 'FROM' => 'glpi_forms_categories', 'WHERE' => ['name' => $name, 'forms_categories_id' => $parent], 'LIMIT' => 1]) as $r) {
                $found = (int) $r['id'];
            }
            $gid = $found > 0 ? $found : (int) (new \Glpi\Form\Category())->add(['name' => $name, 'forms_categories_id' => $parent]);
            if ($gid > 0) {
                $mr = MapRepository::begin($sid, $source, 'catalog_category', $id, 'Glpi\\Form\\Category', $gid, MapRepository::CREATED);
                MapRepository::markOk((int) $mr['id']);
                $n['categories']++;
            }
            return $catId[$id] = $gid;
        };
        foreach (array_keys($byId) as $id) {
            $makeCat((string) $id);
        }

        // ---- one Form per item
        foreach ($cat['items'] as $item) {
            $key = (string) ($item['display_id'] ?? '');
            $row = MapRepository::find($source, 'catalog_item', $key);
            if ($row !== null && (string) $row['status'] === MapRepository::OK && (int) $row['items_id'] > 0) {
                $n['forms_exist']++;
                continue;
            }
            $map = FormMapper::map($item);
            if ($dry) {
                $n['forms']++;
                $n['questions'] += count($map['questions']);
                foreach ($map['questions'] as $q) {
                    $kinds[$q['kind']] = ($kinds[$q['kind']] ?? 0) + 1;
                }
                continue;
            }
            $mr = MapRepository::begin($sid, $source, 'catalog_item', $key, 'Glpi\\Form\\Form', 0, MapRepository::CREATED);
            try {
                $formId = $this->createForm($map, $entity, (int) ($catId[(string) ($item['category_id'] ?? '')] ?? 0), $kinds);
                $DB->update(MapRepository::table(), ['itemtype' => 'Glpi\\Form\\Form', 'items_id' => $formId], ['id' => (int) $mr['id']]);
                MapRepository::markOk((int) $mr['id']);
                $n['forms']++;
                $n['questions'] += count($map['questions']);
            } catch (\Throwable $e) {
                MapRepository::markFailed((int) $mr['id'], $e->getMessage());
                $n['forms_failed']++;
            }
        }

        foreach ($n as $k => $v) {
            $output->writeln(sprintf('%-17s %d', $k, $v));
        }
        ksort($kinds);
        $output->writeln('question kinds: ' . json_encode($kinds));
        $output->writeln($dry ? 'Dry run: nothing written ("forms" = would be created).' : 'Done. Every Form is inactive and a draft: review it, then switch it on.');
        return $n['forms_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array{name:string,description:string,header:string,questions:list<array{name:string,kind:string,required:bool,options:list<string>,note:string}>} $map
     * @param array<string,int> $kinds
     */
    private function createForm(array $map, int $entity, int $category, array &$kinds): int
    {
        global $DB;
        $form   = new \Glpi\Form\Form();
        $formId = (int) $form->add([
            'name' => mb_substr($map['name'], 0, 255), 'description' => $map['description'], 'header' => $map['header'],
            'entities_id' => $entity, 'is_recursive' => 0, 'is_active' => 0, 'is_draft' => 1, 'forms_categories_id' => $category,
        ]);
        if ($formId <= 0) {
            throw new \RuntimeException('GLPI refused the form');
        }
        $section = 0;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_forms_sections', 'WHERE' => ['forms_forms_id' => $formId], 'ORDER' => 'rank', 'LIMIT' => 1]) as $r) {
            $section = (int) $r['id'];
        }
        if ($section <= 0) {
            throw new \RuntimeException('the form has no section');
        }
        foreach ($map['questions'] as $rank => $q) {
            $type  = ['short' => 'QuestionTypeShortText', 'long' => 'QuestionTypeLongText', 'number' => 'QuestionTypeNumber', 'date' => 'QuestionTypeDateTime', 'dropdown' => 'QuestionTypeDropdown', 'checkbox' => 'QuestionTypeCheckbox'][$q['kind']];
            $extra = null;
            if (in_array($q['kind'], ['dropdown', 'checkbox'], true)) {
                $opts = [];
                foreach ($q['options'] as $label) {
                    $opts[\Ramsey\Uuid\Uuid::uuid4()->toString()] = $label;
                }
                $extra = $q['kind'] === 'dropdown' ? ['options' => $opts, 'is_multiple_dropdown' => false] : ['options' => $opts];
            } elseif ($q['kind'] === 'date') {
                $extra = ['is_default_value_current_time' => false, 'is_date_enabled' => true, 'is_time_enabled' => false];
            }
            $qid = (int) (new \Glpi\Form\Question())->add([
                'forms_sections_id' => $section, 'name' => $q['name'], 'type' => self::NS . $type, 'is_mandatory' => $q['required'] ? 1 : 0,
                'vertical_rank' => $rank, 'horizontal_rank' => null, 'description' => $q['note'],
                'extra_data' => $extra === null ? null : json_encode($extra),
            ]);
            if ($qid <= 0) {
                throw new \RuntimeException('GLPI refused a question');
            }
            $kinds[$q['kind']] = ($kinds[$q['kind']] ?? 0) + 1;
        }
        return $formId;
    }
}
