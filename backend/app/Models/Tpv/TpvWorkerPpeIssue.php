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
        'item','qty','size','issued_date','notes',
        // Return lifecycle. Quantities still live in Inventory — these only record
        // how much of THIS issue has come back and in what condition.
        'status','returned_qty','returned_at','returned_by','return_notes',
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

    public function getItemLabelAttribute(): string
    {
        return Ppe::label($this->item);
    }
}
