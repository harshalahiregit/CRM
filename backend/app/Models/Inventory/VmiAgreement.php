<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A vendor-managed inventory agreement — a vendor keeps a set of items stocked. */
class VmiAgreement extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'inventory_vmi_agreements';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'warehouse_id', 'name', 'status',
        'review_frequency', 'note', 'created_by',
    ];

    public function vendor() { return $this->belongsTo(Vendor::class, 'vendor_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class, 'warehouse_id'); }
    public function items() { return $this->hasMany(VmiItem::class, 'agreement_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
