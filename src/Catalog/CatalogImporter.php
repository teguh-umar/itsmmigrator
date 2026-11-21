<?php

namespace GlpiPlugin\Itsmmigrator\Catalog;

use GlpiPlugin\Itsmmigrator\Adapter\AdapterFactory;
use GlpiPlugin\Itsmmigrator\MapRepository;
use GlpiPlugin\Itsmmigrator\Source;

/**
 * Creates GLPI Forms from a source's service catalog. Shared by the console command and the Run page.
 *
 * Every Form is created INACTIVE and NOT flagged is_draft (GLPI uses that flag for the autosaved draft of a new form,
 * hides such a form from the list and purges it), in the source's target entity, not recursive. Nothing shows in the
 * service catalog until an administrator has reviewed a Form and switched it on.
 *
 * No duplicates, whatever happens: an item already imported (map row ok) is never created again; the Form id is written
 * to the item's map row the moment the Form exists, so a run that died half way is found next time and its half-made
 * Form is removed and made again; one batch at a time per source (a database lock), so two clicks cannot race.
 */
final class CatalogImporter
{
    /** Forms made per web request (each is a few dozen writes). */
    public const BATCH = 8;

    private const NS = 'Glpi\\Form\\QuestionType\\';

    /**
     * Read the catalog once and keep it for the following batches (one GET per item: too many for every request).
     *
     * @return array{token:string,categories:int,items:int}
     */
    public static function prepare(Source $src): array
    {
        $cat   = AdapterFactory::make($src->fields, $src->apiKey())->fetchServiceCatalog();
        $token = bin2hex(random_bytes(16));
        file_put_contents(self::file($token), json_encode($cat));
        return ['token' => $token, 'categories' => count($cat['categories']), 'items' => count($cat['items'])];
    }

    /** @return array{categories:list<array<string,mixed>>,items:list<array<string,mixed>>}|null */
    public static function load(string $token): ?array
    {
        $f = self::file($token);
        if (!is_file($f)) {
            return null;
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }

    public static function forget(string $token): void
    {
        $f = self::file($token);
        if (is_file($f)) {
            @unlink($f);
        }
    }

    private static function file(string $token): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            throw new \InvalidArgumentException('bad token');
        }
        return rtrim((string) (defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir()), '/\\') . DIRECTORY_SEPARATOR . 'itsm_catalog_' . $token . '.json';
    }

    /**
     * One pass: the categories (cheap, all of them), then the items that are not imported yet, at most $maxForms of them.
     * One batch at a time per source: when another is running, nothing is done and busy is true.
     *
     * @param array{categories:list<array<string,mixed>>,items:list<array<string,mixed>>} $cat
     * @return array{busy:bool,done:bool,remaining:int,counts:array<string,int>,kinds:array<string,int>}
     */
    public static function process(Source $src, array $cat, bool $dry, ?int $maxForms = null): array
    {
        global $DB;
        $counts = ['categories' => 0, 'categories_exist' => 0, 'forms' => 0, 'forms_exist' => 0, 'forms_failed' => 0, 'questions' => 0];
        $kinds  = [];
        $lock   = 'itsm_catalog_' . (int) $src->getID();
        if (!$dry) {
            $res = $DB->doQuery("SELECT GET_LOCK('" . $lock . "', 0) AS l");
            $got = $res ? $res->fetch_assoc() : null;
            if ((int) ($got['l'] ?? 0) !== 1) {
                return ['busy' => true, 'done' => false, 'remaining' => 0, 'counts' => $counts, 'kinds' => $kinds];
            }
        }
        try {
            $sid    = (int) $src->getID();
            $source = (string) $src->fields['adapter'];
            $entity = (int) $src->fields['entities_id'];

            $byId = [];
            foreach ($cat['categories'] as $c) {
                $byId[(string) $c['id']] = $c;
            }
            $catId   = [];
            $makeCat = function (string $id) use (&$makeCat, &$catId, &$byId, &$counts, $sid, $source, $dry, $DB): int {
                if (isset($catId[$id])) {
                    return $catId[$id];
                }
                $row = MapRepository::find($source, 'catalog_category', $id);
                if ($row !== null && (string) $row['status'] === MapRepository::OK && (int) $row['items_id'] > 0) {
                    $counts['categories_exist']++;
                    return $catId[$id] = (int) $row['items_id'];
                }
                $c      = $byId[$id] ?? null;
                $parent = ($c !== null && !empty($c['parent_id']) && isset($byId[(string) $c['parent_id']])) ? $makeCat((string) $c['parent_id']) : 0;
                if ($c === null || $dry) {
                    $counts['categories']++;
                    return $catId[$id] = 0;
                }
                $name  = mb_substr(trim((string) $c['name']) !== '' ? trim((string) $c['name']) : 'Category', 0, 255);
                $found = 0;
                foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_forms_categories', 'WHERE' => ['name' => $name, 'forms_categories_id' => $parent], 'LIMIT' => 1]) as $r) {
                    $found = (int) $r['id'];
                }
                $gid = $found > 0 ? $found : (int) (new \Glpi\Form\Category())->add(['name' => $name, 'forms_categories_id' => $parent]);
                if ($gid > 0) {
                    $mr = MapRepository::begin($sid, $source, 'catalog_category', $id, 'Glpi\\Form\\Category', $gid, MapRepository::CREATED);
                    MapRepository::markOk((int) $mr['id']);
                    $counts['categories']++;
                }
                return $catId[$id] = $gid;
            };
            foreach (array_keys($byId) as $id) {
                $makeCat((string) $id);
            }

            $made      = 0;
            $remaining = 0;
            foreach ($cat['items'] as $item) {
                $key = (string) ($item['display_id'] ?? '');
                $row = MapRepository::find($source, 'catalog_item', $key);
                if ($row !== null && (string) $row['status'] === MapRepository::OK && (int) $row['items_id'] > 0) {
                    $counts['forms_exist']++;
                    continue;
                }
                if ($maxForms !== null && $made >= $maxForms) {
                    $remaining++;
                    continue;
                }
                $map = FormMapper::map($item);
                if ($dry) {
                    $counts['forms']++;
                    $counts['questions'] += count($map['questions']);
                    foreach ($map['questions'] as $q) {
                        $kinds[$q['kind']] = ($kinds[$q['kind']] ?? 0) + 1;
                    }
                    continue;
                }
                $mr = MapRepository::begin($sid, $source, 'catalog_item', $key, 'Glpi\\Form\\Form', 0, MapRepository::CREATED);
                // A Form left half made by a run that died: it is ours (its id is in this map row), so it is removed and made again.
                if ((int) $mr['items_id'] > 0) {
                    (new \Glpi\Form\Form())->delete(['id' => (int) $mr['items_id']], true);
                    $DB->update(MapRepository::table(), ['items_id' => 0, 'status' => MapRepository::IN_PROGRESS], ['id' => (int) $mr['id']]);
                }
                try {
                    $formId = self::createForm($map, $entity, (int) ($catId[(string) ($item['category_id'] ?? '')] ?? 0), (int) $mr['id'], $kinds);
                    MapRepository::markOk((int) $mr['id']);
                    $counts['forms']++;
                    $counts['questions'] += count($map['questions']);
                    $made++;
                } catch (\Throwable $e) {
                    MapRepository::markFailed((int) $mr['id'], $e->getMessage());
                    $counts['forms_failed']++;
                }
            }
            return ['busy' => false, 'done' => $remaining === 0, 'remaining' => $remaining, 'counts' => $counts, 'kinds' => $kinds];
        } finally {
            if (!$dry) {
                $DB->doQuery("SELECT RELEASE_LOCK('" . $lock . "')");
            }
        }
    }

    /**
     * @param array{name:string,description:string,header:string,questions:list<array{name:string,kind:string,required:bool,options:list<string>,note:string}>} $map
     * @param array<string,int> $kinds
     */
    private static function createForm(array $map, int $entity, int $category, int $mapRowId, array &$kinds): int
    {
        global $DB;
        $form   = new \Glpi\Form\Form();
        $formId = (int) $form->add([
            'name' => mb_substr($map['name'], 0, 255), 'description' => $map['description'], 'header' => $map['header'],
            'entities_id' => $entity, 'is_recursive' => 0, 'is_active' => 0, 'is_draft' => 0, 'forms_categories_id' => $category,
        ]);
        if ($formId <= 0) {
            throw new \RuntimeException('GLPI refused the form');
        }
        // Written at once: if the run dies from here on, the next one finds this Form through its map row.
        $DB->update(MapRepository::table(), ['itemtype' => 'Glpi\\Form\\Form', 'items_id' => $formId], ['id' => $mapRowId]);
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
