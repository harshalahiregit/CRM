<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseCatalogStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A standardized purchasable item (item master). Picked onto procurement lines,
 * whose values are snapshotted at selection — this record is the source, not a
 * live dependency of past lines.
 */
class PurchaseCatalogItem extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'purchase_catalog_items';

    protected $fillable = [
        'tenant_id','sku','name','category','description','uom',
        'default_rate','default_tax','hsn_code','preferred_vendor_id','created_by','status','notes',
    ];

    protected $casts = [
        'default_rate' => 'decimal:2',
        'default_tax'  => 'decimal:2',
    ];

    protected $appends = ['status_label'];

    protected static function booted(): void
    {
        static::creating(function (PurchaseCatalogItem $item) {
            if (empty($item->sku)) {
                $year  = date('Y');
                $count = static::withTrashed()->where('tenant_id', $item->tenant_id)
                               ->whereYear('created_at', $year)->count() + 1;
                $item->sku = 'SKU-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function preferredVendor()
    {
        return $this->belongsTo(Vendor::class, 'preferred_vendor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function isSelectable(): bool
    {
        return Status::isSelectable($this->status);
    }

    public function scopeActive($query)
    {
        return $query->where('status', Status::ACTIVE);
    }
}
