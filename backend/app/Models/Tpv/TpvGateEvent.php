<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §20 — a unified gate movement. Covers Equipment and Material entry/exit (the
 * doc's gaps) alongside Vehicle and Visitor, each stamped with project / work
 * package / location so the live gate view can filter by dimension.
 */
class TpvGateEvent extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_gate_events';

    public const KINDS = ['Person', 'Vehicle', 'Visitor', 'Equipment', 'Material'];

    public const DIRECTIONS = ['Entry', 'Exit'];

    protected $fillable = [
        'tenant_id', 'vendor_id', 'event_kind', 'direction', 'label', 'reference',
        'quantity', 'unit', 'project', 'work_package_id', 'location', 'gate',
        'occurred_at', 'recorded_by', 'details',
    ];

    protected $casts = [
        'quantity'    => 'decimal:3',
        'occurred_at' => 'datetime',
        'details'     => 'array',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function workPackage()
    {
        return $this->belongsTo(TpvWorkPackage::class, 'work_package_id');
    }
}
