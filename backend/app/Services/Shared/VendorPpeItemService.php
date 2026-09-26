<?php

namespace App\Services\Shared;

use App\Exceptions\BusinessException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A vendor's OWN PPE list — the rules, written once for both engines.
 *
 * TPV and Purchase each keep their list in their own table (the vendor masters
 * are separate and neither engine reads the other's tables), so the storage is
 * two thin subclasses and the behaviour is here:
 *
 *   - every read and write is scoped to (tenant, owning vendor) — an id from
 *     another vendor reads as absent, never as permission;
 *   - stock is the vendor's and lives on the row. It never touches the central
 *     Inventory ledger, which is the company's stock;
 *   - drawing stock is a single conditional UPDATE, so two issues racing for the
 *     last helmet cannot both succeed and the count can never go negative.
 */
abstract class VendorPpeItemService
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** The owning-vendor column on the items table. */
    abstract protected function ownerColumn(): string;

    /** The issue model whose rows point back at these items. */
    abstract protected function issueModelClass(): string;

    /** Folder for uploaded photos, under the private local disk. */
    abstract protected function imageFolder(): string;

    /* ── Reads ──────────────────────────────────────────────────────── */

    /**
     * One vendor's list, with how much of each item its workers hold right now.
     *
     * @return Collection<int, Model>
     */
    public function listFor(int $vendorId, int $tenantId, bool $activeOnly = false): Collection
    {
        $items = $this->query($vendorId, $tenantId)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        if ($items->isEmpty()) {
            return $items;
        }

        $issue = $this->issueModelClass();
        $held = $issue::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'issued')
            ->whereIn('vendor_ppe_item_id', $items->pluck('id'))
            ->selectRaw('vendor_ppe_item_id, SUM(qty - returned_qty) AS held')
            ->groupBy('vendor_ppe_item_id')
            ->pluck('held', 'vendor_ppe_item_id');

        return $items->each(function (Model $item) use ($held) {
            $item->setAttribute('issued', (float) ($held[$item->id] ?? 0));
        });
    }

    /** One of the vendor's own items, or 404 — another vendor's id reads as absent. */
    public function findOwned(int $vendorId, int $tenantId, int $itemId): Model
    {
        return $this->query($vendorId, $tenantId)->whereKey($itemId)->first()
            ?? throw new BusinessException('PPE item not found.', 404);
    }

    /* ── Writes ─────────────────────────────────────────────────────── */

    public function create(int $vendorId, int $tenantId, array $data, ?UploadedFile $image = null, ?int $actorId = null): Model
    {
        $model = $this->modelClass();

        $item = $model::create([
            'tenant_id'        => $tenantId,
            $this->ownerColumn() => $vendorId,
            'name'             => $data['name'],
            'category'         => $data['category'] ?? 'other',
            'size'             => $data['size'] ?? null,
            'spec'             => $data['spec'] ?? null,
            'unit'             => filled($data['unit'] ?? null) ? $data['unit'] : 'pcs',
            'qty_in_stock'     => round((float) ($data['qty_in_stock'] ?? 0), 3),
            'is_active'        => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'notes'            => $data['notes'] ?? null,
            // A Purchase vendor token is not a users row, so there may be no id.
            'created_by'       => $actorId,
        ]);

        if ($image) {
            $item->forceFill(['image_path' => $this->storeImage($item, $image)])->save();
        }

        return $item->fresh();
    }

    /** Edit the item, including a stock correction (the vendor restocks its own shelf). */
    public function update(Model $item, array $data, ?UploadedFile $image = null): Model
    {
        $fields = array_intersect_key($data, array_flip([
            'name', 'category', 'size', 'spec', 'unit', 'qty_in_stock', 'is_active', 'notes',
        ]));

        if (array_key_exists('qty_in_stock', $fields)) {
            $fields['qty_in_stock'] = round((float) $fields['qty_in_stock'], 3);
        }
        if (array_key_exists('unit', $fields) && blank($fields['unit'])) {
            $fields['unit'] = 'pcs';
        }
        if (array_key_exists('is_active', $fields)) {
            $fields['is_active'] = (bool) $fields['is_active'];
        }

        $item->fill($fields);

        if ($image) {
            $old = $item->image_path;
            $item->image_path = $this->storeImage($item, $image);
            if ($old) {
                Storage::disk('local')->delete($old);
            }
        }

        $item->save();

        return $item->fresh();
    }

    /**
     * Deactivate rather than delete: issue rows point at the item, and a worker
     * still holding one must keep reading correctly.
     */
    public function setActive(Model $item, bool $active): Model
    {
        $item->forceFill(['is_active' => $active])->save();

        return $item->fresh();
    }

    /**
     * Take stock off the vendor's shelf for an issue.
     *
     * Must run inside the caller's transaction so the decrement and the issue
     * row commit together. The UPDATE carries its own availability guard, so a
     * concurrent issue that took the last unit makes this one fail cleanly.
     */
    public function draw(int $vendorId, int $tenantId, int $itemId, float $qty): Model
    {
        $item = $this->findOwned($vendorId, $tenantId, $itemId);

        if (! $item->is_active) {
            throw new BusinessException('That PPE item has been deactivated.', 422);
        }

        $model = $this->modelClass();
        $taken = $model::query()
            ->whereKey($item->id)
            ->where('qty_in_stock', '>=', $qty)
            ->update(['qty_in_stock' => DB::raw('qty_in_stock - '.$this->sqlNumber($qty)), 'updated_at' => now()]);

        if ($taken === 0) {
            throw new BusinessException(sprintf(
                'Insufficient stock. Only %s %s available on your PPE list.',
                $this->trim((float) $item->qty_in_stock),
                $item->name,
            ), 422);
        }

        return $item->fresh();
    }

    /** Put returned kit back on the vendor's shelf. */
    public function restock(int $itemId, float $qty): void
    {
        $model = $this->modelClass();

        $model::query()->whereKey($itemId)
            ->update(['qty_in_stock' => DB::raw('qty_in_stock + '.$this->sqlNumber($qty)), 'updated_at' => now()]);
    }

    /* ── Images ─────────────────────────────────────────────────────── */

    /** The stored photo as a response, or 404. */
    public function imageResponse(Model $item)
    {
        abort_unless(
            $item->image_path && Storage::disk('local')->exists($item->image_path),
            404,
            'No image.'
        );

        return Storage::disk('local')->response($item->image_path);
    }

    private function storeImage(Model $item, UploadedFile $file): string
    {
        return $file->store(sprintf('%s/%d/%d', $this->imageFolder(), $item->tenant_id, $item->{$this->ownerColumn()}), 'local');
    }

    /* ── Internals ──────────────────────────────────────────────────── */

    private function query(int $vendorId, int $tenantId)
    {
        $model = $this->modelClass();

        return $model::query()
            ->where('tenant_id', $tenantId)
            ->where($this->ownerColumn(), $vendorId);
    }

    /** A quantity safe to splice into SQL: validated numeric, fixed precision. */
    private function sqlNumber(float $qty): string
    {
        return number_format(round($qty, 3), 3, '.', '');
    }

    private function trim(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }
}
