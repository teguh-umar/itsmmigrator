<?php

namespace GlpiPlugin\Itsmmigrator;

/**
 * Reads a CSV exported from a GLPI asset list (or any sheet with the same column names) into plain rows. Pure: no GLPI,
 * no database. The delimiter (; , or tab) and a UTF-8 BOM are detected; column names are matched case-insensitively
 * against a short list of English and Indonesian names. An unknown column is ignored.
 */
final class AssetCsv
{
    /** @var array<string,list<string>> canonical key => accepted header names (lower case) */
    private const ALIASES = [
        'name'         => ['name', 'nama'],
        'type'         => ['type', 'tipe', 'jenis'],
        'model'        => ['model'],
        'manufacturer' => ['manufacturer', 'pabrikan', 'vendor'],
        'serial'       => ['serial number', 'serial', 'nomor seri', 'serial no'],
        'inventory'    => ['inventory number', 'inventory', 'nomor inventaris', 'inventory no'],
        'location'     => ['location', 'lokasi'],
        'status'       => ['status'],
        'comment'      => ['comments', 'comment', 'komentar', 'description'],
    ];

    /**
     * @return array{rows:list<array<string,string>>,columns:list<string>,errors:list<string>}
     */
    public static function parse(string $text): array
    {
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $first = strtok($text, "\n");
        if ($first === false || trim($first) === '') {
            return ['rows' => [], 'columns' => [], 'errors' => ['the file is empty']];
        }
        $delim = self::delimiter($first);
        $lines = [];
        $h     = fopen('php://memory', 'r+');
        fwrite($h, $text);
        rewind($h);
        while (($r = fgetcsv($h, 0, $delim, '"', '')) !== false) {
            if ($r === [null] || $r === []) {
                continue;
            }
            $lines[] = $r;
        }
        fclose($h);
        $head = array_shift($lines) ?? [];
        $col  = [];
        foreach ($head as $i => $title) {
            $t = strtolower(trim((string) $title));
            foreach (self::ALIASES as $key => $names) {
                if (in_array($t, $names, true) && !isset($col[$key])) {
                    $col[$key] = (int) $i;
                }
            }
        }
        if (!isset($col['name'])) {
            return ['rows' => [], 'columns' => array_keys($col), 'errors' => ['no "Name" column in the first line']];
        }
        $rows = [];
        foreach ($lines as $r) {
            $row = [];
            foreach (array_keys(self::ALIASES) as $key) {
                $row[$key] = isset($col[$key]) ? trim((string) ($r[$col[$key]] ?? '')) : '';
            }
            if ($row['name'] !== '') {
                $rows[] = $row;
            }
        }
        return ['rows' => $rows, 'columns' => array_keys($col), 'errors' => []];
    }

    private static function delimiter(string $line): string
    {
        $best = ';';
        $most = -1;
        foreach ([';', ',', "\t"] as $d) {
            $n = substr_count($line, $d);
            if ($n > $most) {
                $most = $n;
                $best = $d;
            }
        }
        return $best;
    }
}
