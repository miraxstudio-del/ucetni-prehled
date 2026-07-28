<?php

namespace App\Services\Imports;

/**
 * Společné čtení CSV pro importy: autodetekce oddělovače (; nebo ,),
 * kódování (UTF-8 / CP1250) a mapování řádků na hlavičku.
 */
class CsvReader
{
    /**
     * @return array<int, array<string, string>> řádky jako [sloupec => hodnota]
     */
    public function read(string $content): array
    {
        // BOM pryč, kódování na UTF-8
        $content = ltrim($content, "\xEF\xBB\xBF");

        if (! mb_check_encoding($content, 'UTF-8')) {
            $converted = @iconv('CP1250', 'UTF-8//IGNORE', $content);
            $content = $converted === false ? $content : $converted;
        }

        $lines = array_values(array_filter(
            preg_split('/\r\n|\n|\r/', $content),
            fn ($line) => trim($line) !== '',
        ));

        if ($lines === []) {
            return [];
        }

        $delimiter = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';

        $header = array_map(
            fn ($column) => mb_strtolower(trim($column)),
            str_getcsv($lines[0], $delimiter, '"', '\\'),
        );

        $rows = [];

        foreach (array_slice($lines, 1) as $line) {
            $values = str_getcsv($line, $delimiter, '"', '\\');
            $row = [];

            foreach ($header as $index => $column) {
                $row[$column] = trim((string) ($values[$index] ?? ''));
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
