<?php

namespace GlpiPlugin\Itsmmigrator\Catalog;

/**
 * One Freshservice service catalog item (with its custom fields) -> the plain description of a GLPI Form. Pure: no GLPI,
 * no database. The writer turns the question kinds into GLPI question types.
 *
 * Kinds: short, long, number, date, dropdown, checkbox (a list of options, several can be ticked).
 * Source field types: text and url -> short; paragraph -> long; number and decimal -> number; date -> date;
 * dropdown -> dropdown; multi-select dropdown -> checkbox; a single checkbox -> checkbox with one option (its label);
 * nested (hierarchical) field -> one short text per level, because the item does not give the choices of a nested field.
 * Anything else becomes a short text, so no field is dropped.
 */
final class FormMapper
{
    /**
     * @param array<string,mixed> $item the item as /service_catalog/items/{id} returns it
     * @return array{name:string,description:string,header:string,questions:list<array{name:string,kind:string,required:bool,options:list<string>,note:string}>}
     */
    public static function map(array $item): array
    {
        $questions = [];
        $fields    = (array) ($item['custom_fields'] ?? []);
        usort($fields, static fn ($a, $b) => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));
        foreach ($fields as $f) {
            if (!empty($f['archived']) || !empty($f['deleted'])) {
                continue;
            }
            $label    = trim((string) ($f['label'] ?? $f['name'] ?? ''));
            $label    = $label !== '' ? $label : 'Question';
            $required = !empty($f['required']);
            $note     = trim((string) ($f['field_options']['placeholder'] ?? ''));
            $type     = (string) ($f['field_type'] ?? '');
            $opts     = self::choices($f);
            switch ($type) {
                case 'custom_paragraph':
                    $questions[] = self::q($label, 'long', $required, [], $note);
                    break;
                case 'custom_number':
                case 'custom_decimal':
                    $questions[] = self::q($label, 'number', $required, [], $note);
                    break;
                case 'custom_date':
                    $questions[] = self::q($label, 'date', $required, [], $note);
                    break;
                case 'custom_dropdown':
                    $questions[] = $opts === [] ? self::q($label, 'short', $required, [], $note) : self::q($label, 'dropdown', $required, $opts, $note);
                    break;
                case 'custom_multi_select_dropdown':
                    $questions[] = $opts === [] ? self::q($label, 'short', $required, [], $note) : self::q($label, 'checkbox', $required, $opts, $note);
                    break;
                case 'custom_checkbox':
                    $questions[] = self::q($label, 'checkbox', $required, [$label], $note);
                    break;
                case 'nested_field':
                    $levels = (array) ($f['nested_fields'] ?? []);
                    usort($levels, static fn ($a, $b) => (int) ($a['level'] ?? 0) <=> (int) ($b['level'] ?? 0));
                    if ($levels === []) {
                        $questions[] = self::q($label, 'short', $required, [], trim($note . ' (a hierarchical field in the source)'));
                    }
                    foreach ($levels as $lv) {
                        $name = trim((string) ($lv['label'] ?? $lv['name'] ?? ''));
                        $questions[] = self::q($name !== '' ? $name : $label, 'short', $required, [], '(a hierarchical field in the source)');
                    }
                    break;
                default: // custom_text, custom_url, anything unknown
                    $questions[] = self::q($label, 'short', $required, [], $note);
            }
        }
        return [
            'name'        => trim((string) ($item['name'] ?? '')) !== '' ? trim((string) $item['name']) : 'Service item',
            'description' => trim(strip_tags((string) ($item['short_description'] ?? ''))),
            'header'      => (string) ($item['description'] ?? ''),
            'questions'   => $questions,
        ];
    }

    /** @return array{name:string,kind:string,required:bool,options:list<string>,note:string} */
    private static function q(string $name, string $kind, bool $required, array $options, string $note): array
    {
        return ['name' => mb_substr($name, 0, 255), 'kind' => $kind, 'required' => $required, 'options' => array_values($options), 'note' => $note];
    }

    /**
     * The choices of a field: from choice_obj ({id, value}) or from choices (a list of [value, id] pairs, or plain strings).
     *
     * @param array<string,mixed> $f
     * @return list<string>
     */
    private static function choices(array $f): array
    {
        $out = [];
        foreach ((array) ($f['choice_obj'] ?? []) as $c) {
            $v = trim((string) (is_array($c) ? ($c['value'] ?? '') : $c));
            if ($v !== '') {
                $out[] = $v;
            }
        }
        if ($out === []) {
            foreach ((array) ($f['choices'] ?? []) as $c) {
                $v = trim((string) (is_array($c) ? ($c[0] ?? '') : $c));
                if ($v !== '') {
                    $out[] = $v;
                }
            }
        }
        return array_values(array_unique($out));
    }
}
