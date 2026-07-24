<?php

namespace App\Services\Inventory;

use App\Models\Inventory\Batch;
use App\Models\Inventory\Location;
use App\Models\Inventory\Product;
use App\Models\Inventory\Serial;
use App\Models\Inventory\Voucher;
use App\Models\Inventory\Warehouse;

/**
 * "Something was scanned. What is it?"
 *
 * A person at a shelf holds one scanner and points it at whatever is in front
 * of them — a product barcode, a serial sticker, a batch label, a bin tag, a
 * printed docket. Making them first tell the app WHICH of those they're about
 * to scan defeats the point of the scanner, so this resolves any code against
 * every kind of thing it could be and says what it found.
 *
 * Order matters: the most specific identity wins. A serial number identifies
 * one physical unit, so it beats the product barcode printed beside it — if
 * both could match, the unit is the more useful answer.
 */
class ScanService
{
    public function __construct(private StockService $stock)
    {
    }

    /**
     * @return array{kind:string,...}|null  null when nothing matches
     */
    public function resolve(string $code, int $tenantId): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return $this->serial($code, $tenantId)
            ?? $this->batch($code, $tenantId)
            ?? $this->location($code, $tenantId)
            ?? $this->voucher($code, $tenantId)
            ?? $this->product($code, $tenantId);
    }

    /* ── One physical unit ──────────────────────────────────────── */

    private function serial(string $code, int $tenantId): ?array
    {
        $s = Serial::forTenant($tenantId)
            ->with(['product:id,sku,name,base_unit', 'warehouse:id,name', 'batch:id,batch_no,expiry_date'])
            ->where('serial_no', $code)->first();

        if (! $s) {
            return null;
        }

        return [
            'kind'    => 'serial',
            'label'   => "Serial {$s->serial_no}",
            'serial'  => $s,
            'product' => $s->product,
            // The one thing worth shouting about a scanned unit: whether it is
            // still ours to sell.
            'note'    => 'Status: '.str_replace('_', ' ', (string) $s->status),
        ];
    }

    /* ── One received lot ───────────────────────────────────────── */

    private function batch(string $code, int $tenantId): ?array
    {
        $b = Batch::forTenant($tenantId)
            ->with(['product:id,sku,name,base_unit', 'warehouse:id,name'])
            ->where(fn ($q) => $q->where('batch_no', $code)->orWhere('lot_number', $code))
            ->first();

        if (! $b) {
            return null;
        }

        $expiry = $b->expiry_date;
        $note = $expiry
            ? ($expiry->isPast()
                ? 'EXPIRED '.$expiry->format('d M Y')
                : 'Expires '.$expiry->format('d M Y'))
            : null;

        return [
            'kind'    => 'batch',
            'label'   => "Batch {$b->batch_no}",
            'batch'   => $b,
            'product' => $b->product,
            'note'    => $note,
        ];
    }

    /* ── A place ────────────────────────────────────────────────── */

    private function location(string $code, int $tenantId): ?array
    {
        $l = Location::forTenant($tenantId)->with('warehouse:id,name')->where('code', $code)->first();
        if ($l) {
            return [
                'kind'     => 'location',
                'label'    => trim(($l->warehouse->name ?? '').' · '.$l->name, ' ·'),
                'location' => $l,
                'note'     => $l->capacity ? "Capacity {$l->capacity}" : null,
            ];
        }

        $w = Warehouse::forTenant($tenantId)->where('code', $code)->first();

        return $w ? ['kind' => 'warehouse', 'label' => $w->name, 'warehouse' => $w, 'note' => null] : null;
    }

    /* ── A document ─────────────────────────────────────────────── */

    private function voucher(string $code, int $tenantId): ?array
    {
        $v = Voucher::forTenant($tenantId)->with('creator:id,name')->where('code', $code)->first();

        return $v ? [
            'kind'    => 'voucher',
            'label'   => $v->type_label.' '.$v->code,
            'voucher' => $v,
            'note'    => ucfirst(str_replace('_', ' ', (string) $v->status)),
        ] : null;
    }

    /* ── An item ────────────────────────────────────────────────── */

    private function product(string $code, int $tenantId): ?array
    {
        $p = Product::forTenant($tenantId)
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code)->orWhere('sku_code', $code))
            ->first();

        if (! $p) {
            return null;
        }

        // A scan of an item is nearly always a prelude to moving it, so the
        // answer carries where it currently is — otherwise the next thing the
        // person does is look that up by hand.
        return [
            'kind'    => 'product',
            'label'   => $p->name,
            'product' => $p,
            'levels'  => $this->stock->levelsFor($p->id, $tenantId),
            'totals'  => $this->stock->totalsFor($p->id, $tenantId),
            'note'    => $p->sku,
        ];
    }
}
