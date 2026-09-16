<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * CSV export.
 *
 * THREE RULES, and the first two are why this is a class rather than a line in
 * each controller:
 *
 *   1. AN EXPORT RUNS THE SAME QUERY AS THE SCREEN. Same filters, same scope,
 *      same permission assertions, in the same order. A download that quietly
 *      ignores the filter someone set is a file they will forward to a customer
 *      believing it says something else.
 *
 *   2. IT IS BOUNDED. MAX_ROWS is a real limit and the response says when it
 *      was hit, rather than either timing out or handing back a truncated file
 *      that looks complete.
 *
 *   3. IT DOES NOT CREATE A SECOND FORMATTING TRUTH. Numbers are written as
 *      plain decimals, dates as ISO. A spreadsheet reads those; a rendering of
 *      "₹1,23,456.78" is text and will not add up.
 *
 * FORMULA INJECTION. A cell beginning =, +, - or @ is executed by Excel when the
 * file is opened. A customer name is not under our control, so every value that
 * starts with one is prefixed with a single quote. It costs nothing and closes
 * the one attack an exported CSV genuinely has.
 */
final class Export
{
    /** The most rows one download will contain. */
    public const MAX_ROWS = 5000;

    /**
     * @param array<string, string>     $columns key in each row => header text
     * @param list<array<string, mixed>> $rows
     */
    public static function csv(string $name, array $columns, array $rows, int $total): never
    {
        $truncated = $total > count($rows);
        $filename = $name . '-' . gmdate('Y-m-d') . '.csv';

        if (PHP_SAPI === 'cli') {
            throw new ResponseSent(200, [
                'csv' => self::render($columns, $rows),
                'filename' => $filename,
                'truncated' => $truncated,
            ]);
        }

        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        // The browser cannot read a header it was not allowed to see, and the
        // download is a plain navigation, so the warning travels in the file.
        header('X-Export-Truncated: ' . ($truncated ? '1' : '0'));

        echo self::render($columns, $rows);
        if ($truncated) {
            echo "\n\"Only the first " . count($rows) . ' of ' . $total
                . ' rows are included. Narrow the filters to export the rest."' . "\n";
        }
        exit;
    }

    /**
     * @param array<string, string>      $columns
     * @param list<array<string, mixed>> $rows
     */
    private static function render(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        // A BOM, so Excel opens a UTF-8 file as UTF-8 rather than mangling every
        // name with an accent in it.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_values($columns), ',', '"', '\\');

        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($columns) as $key) {
                $line[] = self::cell($row[$key] ?? null);
            }
            fputcsv($handle, $line, ',', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
        }

        $text = (string) $value;

        // Neutralise a leading character a spreadsheet would treat as a formula.
        if ($text !== '' && str_contains("=+-@\t\r", $text[0])) {
            return "'" . $text;
        }

        return $text;
    }
}
