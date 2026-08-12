<?php

namespace App\Models\Purchase;

use App\Models\Inventory\Product;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One PPE hand-out to a Purchase worker.
 *
 * The stock itself lives in the central Inventory ledger — this row is the
 * vendor-facing record of who holds what, and carries the reference the
 * inventory movement points back to (reference_type = 'purchase_ppe_issue').
 *
 * Mirrors TpvWorkerPpeIssue so both modules read the same way; only the worker
 * FK differs. `item` is a name snapshot so the record still reads correctly
 * after the product is renamed in Inventory.
 */
class PurchaseWorkerPpeIssue extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_worker_ppe_issues';

    protected $fillable = [
        'tenant_id', 'purchase_worker_id', 'issued_by', 'inventory_item_id',
        'item', 'qty', 'size', 'issued_date', 'notes', 'status',
        'returned_qty', 'returned_at', 'returned_by', 'return_notes',
    ];

    protected $casts = [
        'qty'          => 'float',
        'returned_qty' => 'float',
        'issued_date'  => 'date',
        'returned_at'  => 'datetime',
    ];

    public function worker()
    {
        return $this->belongsTo(PurchaseWorker::class, 'purchase_worker_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'inventory_item_id');
    }

    /** Still held: issued and not fully handed back. */
    public function isOutstanding(): bool
    {
        return $this->status === 'issued' && (float) $this->qty > (float) $this->returned_qty;
    }
}
