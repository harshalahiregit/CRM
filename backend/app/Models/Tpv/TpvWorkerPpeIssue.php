<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Tpv\TpvPpeItem as Ppe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TpvWorkerPpeIssue extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'tpv_worker_ppe_issues';

    protected $fillable = [
        'tenant_id','tpv_worker_id','issued_by','inventory_item_id',
        'project','site',
        'item','qty','size','issued_date','notes',
        // Return lifecycle. Quantities still live in Inventory — these only record
        // how much of THIS issue has come back and in what condition.
        'status','returned_qty','returned_at','returned_by','return_notes',
        // §17 — a replacement chains a new issue to the one it superseded.
        'replaced_by_id',
    ];

    /**
     * Issue lifecycle statuses (§17). 'issued' is the live/held state; the rest
     * are terminal outcomes of a return, write-off, replacement, or consumption.
     */
    public const STATUS_ISSUED   = 'issued';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_DAMAGED  = 'damaged';
    public const STATUS_LOST     = 'lost';
    public const STATUS_REPLACED = 'replaced';   // worn out and swapped for fresh kit
    public const STATUS_USED     = 'used';        // consumable spent — never coming back

    public const STATUSES = [
        self::STATUS_ISSUED, self::STATUS_RETURNED, self::STATUS_DAMAGED,
        self::STATUS_LOST, self::STATUS_REPLACED, self::STATUS_USED,
    ];

    protected $casts = [
        'qty'          => 'integer',
        'issued_date'  => 'date',
        'returned_qty' => 'decimal:3',
        'returned_at'  => 'datetime',
    ];

    /** The Inventory product this issue drew from — the single source of stock. */
    public function product()
    {
        return $this->belongsTo(\App\Models\Inventory\Product::class, 'inventory_item_id');
    }

    protected $appends = ['item_label'];

    public function worker()
    {
        return $this->belongsTo(TpvWorker::class, 'tpv_worker_id');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** The fresh issue that superseded this one when it was replaced (§17). */
    public function replacement()
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function getItemLabelAttribute(): string
    {
        return Ppe::label($this->item);
    }
}
