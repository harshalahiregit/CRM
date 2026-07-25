<?php

namespace App\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Generic CSV / XLSX read + write helper backed by OpenSpout.
 *
 * Reusable across modules: reads a spreadsheet into a plain array-of-rows and
 * streams an array-of-rows back out as a real .csv or .xlsx download. Keeps
 * import/export logic format-agnostic — callers deal only in string cells.
 */
class Spreadsheet
{
    /**
     * Read every row of the first sheet into a plain array.
     *
     * @return array<int, array<int, string>> rows of string cells (header first)
     */
    public static function readRows(string $path, string $extension): array
    {
        $reader = self::isXlsx($extension) ? new XlsxReader() : new CsvReader();
        $reader->open($path);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(
                    static fn ($cell) => $cell === null ? '' : (string) $cell,
                    $row->toArray(),
                );
            }
            break; // first sheet only
        }
        $reader->close();

        return $rows;
    }

    /**
     * Stream an array-of-rows as a downloadable .csv or .xlsx file.
     *
     * @param array<int, array<int, mixed>> $rows header row first, then data
     */
    public static function download(array $rows, string $filename, string $extension): BinaryFileResponse
    {
        $xlsx   = self::isXlsx($extension);
        $tmp    = tempnam(sys_get_temp_dir(), 'sheet_');
        $writer = $xlsx ? new XlsxWriter() : new CsvWriter();

        $writer->openToFile($tmp);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(
                static fn ($v) => $v === null ? '' : $v,
                $row,
            )));
        }
        $writer->close();

        $mime = $xlsx
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv';

        return response()->download($tmp, $filename, ['Content-Type' => $mime])
            ->deleteFileAfterSend(true);
    }

    private static function isXlsx(string $extension): bool
    {
        return in_array(strtolower($extension), ['xlsx', 'xls'], true);
    }
}
