<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Product;
use App\Models\Inventory\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Bulk import (blueprint §1: "Import items" and "Import opening stock").
 *
 * Reads .xlsx and .csv without a third-party library — an XLSX is a zip of XML,
 * so ZipArchive + SimpleXML is enough to pull the first worksheet out. Keeping
 * it dependency-free means the import can never break on a composer upgrade.
 *
 * Every import is all-or-nothing per row and reports what it did: a file with
 * three good rows and one bad one imports the three and tells you about the
 * fourth, rather than failing the whole upload or silently skipping it.
 */
class ImportService
{
    public function __construct(
        private ProductService $products,
        private StockService $stock,
    ) {
    }

    /* ── Item import ────────────────────────────────────────────── */

    /** Columns the item importer understands (header → product field). */
    public const ITEM_COLUMNS = [
        'name'          => 'Commodity Name (required)',
        'sku'           => 'Sku Code — blank to auto-generate',
        'barcode'       => 'Barcode — blank to auto-generate',
        'description'   => 'Description',
        'brand'         => 'Brand',
        'origin'        => 'Origin',
        'base_unit'     => 'Unit (e.g. pcs, box)',
        'cost_price'    => 'Purchase Price',
        'sale_price'    => 'Sale Price',
        'gst_rate'      => 'Tax %',
        'min_stock'     => 'Minimum stock',
        'max_stock'     => 'Maximum stock',
        'reorder_point' => 'Reorder point',
        'tags'          => 'Tags (comma separated)',
    ];

    /** Columns the opening-stock importer understands. */
    public const OPENING_COLUMNS = [
        'sku'       => 'Sku Code or Barcode (required)',
        'warehouse' => 'Warehouse name or code (required)',
        'quantity'  => 'Opening quantity (required)',
    ];

    public function importItems(UploadedFile $file, int $tenantId, int $userId): array
    {
        $rows = $this->readRows($file);
        $result = ['created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];

        foreach ($rows as $i => $row) {
            $line = $i + 2;   // +1 for the header, +1 for 1-based rows

            try {
                $data = $this->mapItemRow($row);

                if (empty($data['name'])) {
                    throw new BusinessException('Commodity Name is required.');
                }

                // A matching SKU means "update this item", not "make a duplicate".
                $existing = ! empty($data['sku'])
                    ? Product::forTenant($tenantId)->where('sku', $data['sku'])->first()
                    : null;

                if ($existing) {
                    $this->products->update($existing->id, $data, $tenantId);
                    $result['updated']++;
                } else {
                    $this->products->create($data, $tenantId, $userId);
                    $result['created']++;
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                // Cap the error list — a 500-row disaster shouldn't return 500 messages.
                if (count($result['errors']) < 25) {
                    $result['errors'][] = "Row {$line}: ".$e->getMessage();
                }
            }
        }

        return $result;
    }

    private function mapItemRow(array $row): array
    {
        $data = [];

        foreach (array_keys(self::ITEM_COLUMNS) as $field) {
            $v = $row[$field] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $data[$field] = $v;
        }

        foreach (['cost_price', 'sale_price', 'gst_rate', 'min_stock', 'max_stock', 'reorder_point'] as $num) {
            if (isset($data[$num])) {
                $clean = preg_replace('/[^0-9.\-]/', '', (string) $data[$num]);
                $data[$num] = $clean === '' ? null : (float) $clean;
            }
        }

        if (isset($data['tags'])) {
            $data['tags'] = array_values(array_filter(array_map('trim', explode(',', (string) $data['tags']))));
        }

        return $data;
    }

    /* ── Opening stock import ───────────────────────────────────── */

    /**
     * Sets each item's balance at a warehouse to the figure in the file. Uses
     * adjustTo() so the difference is written to the ledger as a movement —
     * an opening balance that appeared without a movement would be exactly the
     * kind of unexplained number this module exists to prevent.
     */
    public function importOpeningStock(UploadedFile $file, int $tenantId, int $userId): array
    {
        $rows = $this->readRows($file);
        $result = ['applied' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        $warehouses = Warehouse::forTenant($tenantId)->get(['id', 'name', 'code']);

        foreach ($rows as $i => $row) {
            $line = $i + 2;

            try {
                $sku = trim((string) ($row['sku'] ?? ''));
                $whRaw = trim((string) ($row['warehouse'] ?? ''));
                $qtyRaw = $row['quantity'] ?? null;

                if ($sku === '' || $whRaw === '' || $qtyRaw === null || $qtyRaw === '') {
                    throw new BusinessException('Sku, warehouse and quantity are all required.');
                }

                $product = Product::forTenant($tenantId)
                    ->where(fn ($q) => $q->where('sku', $sku)->orWhere('barcode', $sku))
                    ->first()
                    ?? throw new BusinessException("No item matches “{$sku}”.");

                $warehouse = $warehouses->first(fn ($w) => strcasecmp($w->name, $whRaw) === 0 || strcasecmp((string) $w->code, $whRaw) === 0)
                    ?? throw new BusinessException("No warehouse matches “{$whRaw}”.");

                $qty = (float) preg_replace('/[^0-9.\-]/', '', (string) $qtyRaw);

                $moved = $this->stock->adjustTo(
                    $product->id, $warehouse->id, $qty, $tenantId, $userId,
                    'Opening stock import',
                );

                // adjustTo returns null when the counted figure already matched.
                $moved ? $result['applied']++ : $result['skipped']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                if (count($result['errors']) < 25) {
                    $result['errors'][] = "Row {$line}: ".$e->getMessage();
                }
            }
        }

        return $result;
    }

    /* ── File reading ───────────────────────────────────────────── */

    /**
     * Rows as [header => value], from .xlsx or .csv. Headers are normalised
     * (lowercased, non-alphanumerics collapsed to _) so "Sale Price", "sale
     * price" and "Sale_Price" all land on the same field.
     */
    private function readRows(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        $grid = match ($ext) {
            'xlsx'        => $this->readXlsx($file->getRealPath()),
            'csv', 'txt'  => $this->readCsv($file->getRealPath()),
            default       => throw new BusinessException('Upload a .xlsx or .csv file.', 422),
        };

        if (count($grid) < 2) {
            throw new BusinessException('That file has no data rows under its header.', 422);
        }

        $headers = array_map(fn ($h) => $this->normaliseHeader($h), array_shift($grid));

        $rows = [];
        foreach ($grid as $line) {
            $row = [];
            foreach ($headers as $i => $h) {
                if ($h !== '') {
                    $row[$h] = isset($line[$i]) ? trim((string) $line[$i]) : null;
                }
            }
            // Skip entirely blank lines — spreadsheets are full of them.
            if (array_filter($row, fn ($v) => $v !== null && $v !== '')) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function normaliseHeader(?string $h): string
    {
        $h = strtolower(trim((string) $h));
        $h = preg_replace('/[^a-z0-9]+/', '_', $h);
        $h = trim($h, '_');

        // Friendly aliases for the labels people actually put in spreadsheets.
        return match ($h) {
            'commodity_name', 'product_name', 'item_name', 'item'   => 'name',
            'sku_code', 'code', 'commodity_code', 'item_code'       => 'sku',
            'commodity_barcode', 'bar_code'                         => 'barcode',
            'purchase_price', 'cost'                                => 'cost_price',
            'selling_price', 'price', 'sale'                        => 'sale_price',
            'tax', 'tax_rate', 'gst'                                => 'gst_rate',
            'unit', 'unit_name', 'uom'                              => 'base_unit',
            'minimum_stock', 'min'                                  => 'min_stock',
            'maximum_stock', 'max'                                  => 'max_stock',
            'reorder', 'reorder_level'                              => 'reorder_point',
            'warehouse_name', 'warehouse_code', 'godown'            => 'warehouse',
            'qty', 'opening_stock', 'opening_qty', 'stock'          => 'quantity',
            default                                                 => $h,
        };
    }

    private function readCsv(string $path): array
    {
        $out = [];
        $fh = fopen($path, 'r');

        if (! $fh) {
            throw new BusinessException('That file could not be opened.', 422);
        }

        // Strip a UTF-8 BOM — Excel writes one and it corrupts the first header.
        $first = fgets($fh);
        rewind($fh);
        if ($first !== false && str_starts_with($first, "\xEF\xBB\xBF")) {
            fseek($fh, 3);
        }

        while (($line = fgetcsv($fh, 0, ',')) !== false) {
            $out[] = $line;
        }
        fclose($fh);

        return $out;
    }

    /**
     * Minimal XLSX reader: an .xlsx is a zip holding sharedStrings.xml (the
     * string pool) and worksheets/sheet1.xml (the cells). Cells of type "s"
     * index into the pool; everything else carries its value inline. Only the
     * first worksheet is read, which is where an import template's data lives.
     */
    private function readXlsx(string $path): array
    {
        $blob = @file_get_contents($path);

        if ($blob === false || ! str_starts_with($blob, "PK")) {
            throw new BusinessException('That .xlsx file could not be read.', 422);
        }

        $shared = [];
        if (($xml = $this->zipEntry($blob, 'xl/sharedStrings.xml')) !== null) {
            $sx = @simplexml_load_string($xml);
            foreach ($sx?->si ?? [] as $si) {
                // A string can be one <t>, or split across formatting runs (<r><t>).
                $shared[] = isset($si->t) && count($si->r ?? []) === 0
                    ? (string) $si->t
                    : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r ?? [])));
            }
        }

        $sheet = $this->zipEntry($blob, 'xl/worksheets/sheet1.xml');

        if ($sheet === null) {
            throw new BusinessException('That .xlsx file has no readable worksheet.', 422);
        }

        $sx = @simplexml_load_string($sheet);
        if (! $sx) {
            throw new BusinessException('That .xlsx file could not be parsed.', 422);
        }

        $grid = [];
        foreach ($sx->sheetData->row ?? [] as $row) {
            $cells = [];
            foreach ($row->c ?? [] as $c) {
                $ref = (string) $c['r'];                       // e.g. "C12"
                $col = $this->colIndex(preg_replace('/\d/', '', $ref));
                $type = (string) $c['t'];

                $value = match ($type) {
                    's'      => $shared[(int) $c->v] ?? '',    // shared string
                    'inlineStr' => (string) ($c->is->t ?? ''),
                    default  => isset($c->v) ? (string) $c->v : '',
                };

                $cells[$col] = $value;
            }

            if ($cells) {
                // Fill gaps so column positions line up with the header row.
                $grid[] = array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells);
            }
        }

        return $grid;
    }

    /**
     * Pull one entry out of a ZIP archive held in memory, without ext-zip.
     *
     * This build of PHP segfaults with the zip extension loaded, and an .xlsx is
     * just a ZIP, so the few bytes of the format we need are read directly:
     * locate the End-Of-Central-Directory record, walk the central directory to
     * the named entry, jump to its local header, then inflate. Only the two
     * compression methods a spreadsheet ever uses are supported — stored (0) and
     * deflate (8) — and anything else is reported rather than silently skipped.
     *
     * Returns null when the entry isn't in the archive.
     */
    private function zipEntry(string $blob, string $name): ?string
    {
        // EOCD is at the end, after a variable-length comment — scan backwards.
        $eocd = strrpos($blob, "PK\x05\x06");
        if ($eocd === false) {
            throw new BusinessException('That .xlsx file is not a readable archive.', 422);
        }

        $entries = unpack('vcount', substr($blob, $eocd + 10, 2))['count'] ?? 0;
        $offset = unpack('Voffset', substr($blob, $eocd + 16, 4))['offset'] ?? 0;

        for ($i = 0; $i < $entries; $i++) {
            if (substr($blob, $offset, 4) !== "PK\x01\x02") {
                break;
            }

            // Offsets are those of the 46-byte central-directory header, so the
            // substr starts at the header and every @ below is the spec's offset.
            $h = unpack('@10/vmethod/@20/Vcsize/@28/vnamelen/vextralen/vcommentlen/@42/Vlocal', substr($blob, $offset, 46));
            $entryName = substr($blob, $offset + 46, $h['namelen']);

            if ($entryName === $name) {
                // The local header repeats name/extra lengths — and they can differ
                // from the central directory's, so read them from the local header.
                $lh = unpack('@26/vnamelen/vextralen', substr($blob, $h['local'], 30));
                $dataAt = $h['local'] + 30 + $lh['namelen'] + $lh['extralen'];
                $raw = substr($blob, $dataAt, $h['csize']);

                return match ($h['method']) {
                    0 => $raw,                                   // stored
                    8 => (@gzinflate($raw) ?: null),             // deflate
                    default => throw new BusinessException('That .xlsx uses an unsupported compression method. Save it as CSV instead.', 422),
                };
            }

            $offset += 46 + $h['namelen'] + $h['extralen'] + $h['commentlen'];
        }

        return null;
    }

    /** "A" → 0, "B" → 1, … "AA" → 26. */
    private function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }

    /* ── Bulk actions on the item list ──────────────────────────── */

    /**
     * Apply one action to many items. Each row is guarded individually and
     * failures are reported per id rather than aborting the batch, mirroring
     * how the helpdesk bulk endpoint behaves.
     */
    public function bulk(int $tenantId, string $action, array $ids, $value = null): array
    {
        $ok = 0;
        $failed = [];

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            try {
                $product = Product::forTenant($tenantId)->findOrFail($id);

                match ($action) {
                    'delete'       => $this->products->delete($id, $tenantId),
                    'status'       => $product->update(['status' => $value === 'inactive' ? 'inactive' : 'active']),
                    'category'     => $product->update(['category_id' => $value !== '' && $value !== null ? (int) $value : null]),
                    'add_tags'     => $product->update(['tags' => $this->mergeTags($product->tags, $value, true)]),
                    'remove_tags'  => $product->update(['tags' => $this->mergeTags($product->tags, $value, false)]),
                    default        => throw new BusinessException('Unknown bulk action.'),
                };

                $ok++;
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return ['ok' => $ok, 'failed' => $failed];
    }

    private function mergeTags(?array $current, $value, bool $add): ?array
    {
        $incoming = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string) $value)));
        $current = $current ?? [];

        $next = $add
            ? array_values(array_unique([...$current, ...$incoming]))
            : array_values(array_diff($current, $incoming));

        return $next ?: null;
    }
}
