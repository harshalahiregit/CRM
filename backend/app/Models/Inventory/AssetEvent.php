<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** One entry in an asset's history: service, repair, inspection, assignment… */
class AssetEvent extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_asset_events';

    public const TYPES = ['service', 'repair', 'inspection', 'assigned', 'returned', 'transferred', 'replaced', 'lost', 'damaged', 'note'];

    protected $fillable = [
        'tenant_id', 'asset_id', 'type', 'description', 'cost', 'vendor',
        'next_due', 'performed_at', 'performed_by', 'employee_id',
    ];

    protected $casts = [
        'cost'         => 'decimal:2',
        'next_due'     => 'date',
        'performed_at' => 'datetime',
    ];

    public function asset() { return $this->belongsTo(Asset::class, 'asset_id'); }
    public function performer() { return $this->belongsTo(User::class, 'performed_by'); }
    public function employee() { return $this->belongsTo(\App\Models\Hr\HrEmployee::class, 'employee_id'); }
}
