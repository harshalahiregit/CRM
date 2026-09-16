<?php

namespace App\Support\Shared;

use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\File;

/**
 * Reading a bulk worker sheet: the parts that are the same for any engine.
 *
 * TPV has imported workers from a CSV, an Excel book or a ZIP of both-plus-photos
 * for a long time, and the fiddly knowledge is all in the edges rather than the
 * happy path — the UTF-8 BOM Excel writes into cell A1, the four date formats
 * people actually type, the way Excel silently rewrites a 12-digit Aadhaar as
 * 1.23E+11 the moment the sheet is saved, matching a photo to a row by any of
 * five plausible names. Purchase had none of it: no bulk import at all, so a
 * vendor with forty workers registered them one at a time.
 *
 * Copying 230 lines across would have been the fifth time today that a Purchase
 * copy of a TPV screen drifted and broke. So the READING lives here and the
 * WRITING stays in each engine's own service, against its own table — the
 * isolation that matters is the data, not the CSV parser.
 */
class WorkerImport
{
    /** Photo extensions matched out of a ZIP. */
    private const IMAGES = ['jpg', 'jpeg', 'png', 'webp'];

    /** Tabular formats the data sheet may arrive in. */
    private const SHEETS = ['csv', 'xls', 'xlsx'];

    /**
     * Open the upload and hand back its rows and any photos travelling with it.
     *
     * The caller MUST invoke the returned `cleanup` when finished — a ZIP is
     * extracted to a temporary directory that nothing else will remove.
     *
     * @return array{rows: array<int, array<int, string>>, photos: array<string, string>, cleanup: callable}
     */
    public static function read(mixed $file): array
    {
        $path = $file->getRealPath();
        $ext = strtolower($file->getClientOriginalExtension());

        $photos = [];
        $tempDir = null;
        $dataFile = $path;
        $dataExt = $ext;

        if ($ext === 'zip') {
            [$dataFile, $dataExt, $photos, $tempDir] = self::openZip($path);
        }

        $cleanup = function () use (&$tempDir) {
            if ($tempDir && file_exists($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        };

        try {
            $rows = match (true) {
                in_array($dataExt, ['csv', 'txt'], true) => self::readCsv($dataFile),
                in_array($dataExt, ['xls', 'xlsx'], true) => self::readSpreadsheet($dataFile),
                default => throw new BusinessException("Unsupported file format: {$ext}"),
            };
        } catch (\Throwable $e) {
            $cleanup();
            throw $e;
        }

        return ['rows' => $rows, 'photos' => $photos, 'cleanup' => $cleanup];
    }

    /** @return array{0:string,1:string,2:array<string,string>,3:string} */
    private static function openZip(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new BusinessException('Failed to open uploaded ZIP file.');
        }

        $tempDir = storage_path('app/temp_bulk_'.uniqid());
        $zip->extractTo($tempDir);
        $zip->close();

        $photos = [];
        $dataFile = null;
        $dataExt = null;

        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tempDir));
        foreach ($walk as $f) {
            if ($f->isDir()) {
                continue;
            }
            $ext = strtolower($f->getExtension());
            $name = $f->getFilename();

            if (in_array($ext, self::SHEETS, true) && ! $dataFile) {
                $dataFile = $f->getRealPath();
                $dataExt = $ext;
            } elseif (in_array($ext, self::IMAGES, true)) {
                // Keyed both ways: people reference a photo with or without its
                // extension, and neither is wrong.
                $photos[strtolower($name)] = $f->getRealPath();
                $photos[strtolower(pathinfo($name, PATHINFO_FILENAME))] = $f->getRealPath();
            }
        }

        if (! $dataFile) {
            File::deleteDirectory($tempDir);
            throw new BusinessException('ZIP archive must contain a CSV or Excel file (workers.csv / workers.xlsx).');
        }

        return [$dataFile, $dataExt, $photos, $tempDir];
    }

    /** @return array<int, array<int, string>> */
    private static function readCsv(string $file): array
    {
        $rows = [];
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return $rows;
        }

        $header = true;
        while (($row = fgetcsv($handle, 2000, ',')) !== false) {
            // Excel writes a UTF-8 BOM into the first cell, which turns the first
            // column header into garbage and, worse, the first VALUE on a
            // header-less file.
            if (isset($row[0])) {
                $row[0] = preg_replace('/\x{EF}\x{BB}\x{BF}/u', '', (string) $row[0]);
            }
            if ($header) {
                $header = false;

                continue;
            }
            $rows[] = array_map(fn ($c) => trim((string) $c), $row);
        }
        fclose($handle);

        return $rows;
    }

    /** @return array<int, array<int, string>> */
    private static function readSpreadsheet(string $file): array
    {
        if (! class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            throw new BusinessException('Excel parsing requires the PhpSpreadsheet library. Please use CSV format instead.');
        }

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet();
        $rows = [];
        $header = true;

        foreach ($sheet->getRowIterator() as $row) {
            if ($header) {
                $header = false;

                continue;
            }
            $cells = $row->getCellIterator();
            $cells->setIterateOnlyExistingCells(false);

            $data = [];
            foreach ($cells as $cell) {
                $data[] = trim((string) $cell->getFormattedValue());
            }
            if (implode('', $data) === '') {
                continue;   // a blank row is spacing, not a worker
            }
            $rows[] = $data;
        }

        return $rows;
    }

    /**
     * Why this Aadhaar cannot be accepted, or null when it is fine.
     *
     * Excel rewrites a 12-digit number as 1.23E+11 the moment the sheet is
     * opened and saved, and "invalid Aadhaar" sends the reader hunting for a
     * typo that is not there. Naming the cause is the difference between a
     * one-minute fix and an afternoon.
     */
    public static function aadhaarProblem(string $aadhaar, int $rowNumber): ?string
    {
        if ($aadhaar === '' || preg_match('/^\d{12}$/', $aadhaar)) {
            return null;
        }

        return preg_match('/^\d(\.\d+)?E\+?\d+$/i', $aadhaar)
            ? "Row {$rowNumber}: Aadhaar \"{$aadhaar}\" was saved by Excel in scientific notation. Format the column as Text (or prefix the value with an apostrophe) and upload again."
            : "Row {$rowNumber}: Aadhaar \"{$aadhaar}\" is not 12 digits.";
    }

    /** A date of birth out of whatever the sheet happened to contain. */
    public static function parseDate(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $raw);
            if ($dt) {
                return $dt->format('Y-m-d');
            }
        }

        $ts = strtotime($raw);

        return $ts ? date('Y-m-d', $ts) : null;
    }

    /**
     * The photo belonging to a row, stored on the public disk.
     *
     * Tried against every name the row could plausibly be filed under, because
     * whoever zipped the folder named the files for themselves, not for us.
     *
     * @param  array<string,string>  $photos
     * @param  array<int,string|null>  $keys
     */
    public static function storePhoto(array $photos, array $keys, string $dir = 'workers/photos'): ?string
    {
        foreach (array_filter(array_map(fn ($k) => strtolower(trim((string) $k)), $keys)) as $key) {
            if (! isset($photos[$key]) || ! file_exists($photos[$key])) {
                continue;
            }
            $source = $photos[$key];
            $dest = $dir.'/bulk_'.uniqid().'.'.pathinfo($source, PATHINFO_EXTENSION);
            \Storage::disk('public')->put($dest, file_get_contents($source));

            return $dest;
        }

        return null;
    }

    /**
     * The result, said honestly.
     *
     * "0 worker(s) imported successfully" is what made a failed import read as a
     * success. When nothing landed, that is the first thing said, and the reason
     * follows — and every skipped row is NAMED, because "3 skipped" is
     * indistinguishable from an import that quietly broke.
     *
     * @param  array<int,string>  $duplicates
     * @param  array<int,string>  $errors
     * @return array{status:string, message:string, inserted:int, skipped:int, duplicates:array, errors:array}
     */
    public static function summarise(int $inserted, int $skipped, array $duplicates, array $errors): array
    {
        if ($inserted > 0) {
            $message = "{$inserted} worker(s) imported successfully.";
            if ($skipped > 0) {
                $message .= " {$skipped} skipped.";
            }
        } else {
            $message = 'Nothing was imported.';
            $message .= $skipped > 0
                ? " All {$skipped} row(s) were skipped — see the detail below."
                : ' The file had no usable rows.';
        }

        if ($errors !== []) {
            $message .= ' '.count($errors).' row(s) could not be read.';
        }

        return [
            'status' => $inserted > 0 ? 'success' : 'warning',
            'message' => $message,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'duplicates' => $duplicates,
            'errors' => $errors,
        ];
    }
}
