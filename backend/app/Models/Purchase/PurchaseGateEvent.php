<?php

namespace App\Models\Purchase;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Anything other than a badged person crossing the gate — equipment, material,
 * a vehicle, a visitor.
 *
 * These carry no badge and have no readiness to check, so unlike a scan there is
 * no decision: only a direction, a reference and what it was. Soft-deleted
 * because a mistyped delivery is a correction, not a thing that happened.
 */
class PurchaseGateEvent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id',
        'event_kind', 'direction', 'label', 'reference',
        'quantity', 'unit', 'project', 'location', 'gate',
        'occurred_at', 'recorded_by', 'details',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'quantity'    => 'float',
    ];

    /** Mirrors TPV §20 — the kinds a gate records. */
    public const KINDS = ['equipment', 'material', 'vehicle', 'visitor', 'person'];

    public const DIRECTIONS = ['in', 'out'];

    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', (int) $tenantId);
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }
}
