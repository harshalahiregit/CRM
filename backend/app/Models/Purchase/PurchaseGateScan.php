<?php

namespace App\Models\Purchase;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One badge scan of a worker at the site gate.
 *
 * The decision and its reasons are STORED rather than recomputed on read: a
 * worker admitted last week under rules that have since changed was still
 * admitted, and re-deriving the verdict today would quietly rewrite history.
 *
 * No SoftDeletes, on purpose — a gate log that can be quietly removed is not
 * evidence of anything.
 */
class PurchaseGateScan extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'purchase_worker_id',
        'decision', 'reasons', 'action',
        'gate', 'ip', 'user_agent', 'scanned_at',
    ];

    protected $casts = [
        'reasons'    => 'array',
        'scanned_at' => 'datetime',
    ];

    public const ALLOW = 'allow';
    public const DENY  = 'deny';

    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', (int) $tenantId);
    }

    public function worker()
    {
        return $this->belongsTo(PurchaseWorker::class, 'purchase_worker_id');
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }
}
