<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A supplier the tenant buys from. */
class Vendor extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'inventory_vendors';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'email', 'phone', 'gstin', 'address',
        'city', 'state', 'country', 'payment_terms', 'lead_time_days',
        'status', 'note', 'created_by',
    ];

    protected $casts = ['lead_time_days' => 'integer'];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function productLinks() { return $this->hasMany(ProductVendor::class, 'vendor_id'); }
}
