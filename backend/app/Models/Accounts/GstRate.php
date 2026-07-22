<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A GST slab with its CGST/SGST/IGST/Cess components. Effective-dated (data, not code). */
class GstRate extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_gst_rates';

    protected $fillable = [
        'tenant_id', 'name', 'rate_percent', 'cgst', 'sgst', 'igst', 'cess',
        'effective_from', 'effective_to', 'is_active',
    ];

    protected $casts = [
        'rate_percent'   => 'decimal:3',
        'cgst'           => 'decimal:3',
        'sgst'           => 'decimal:3',
        'igst'           => 'decimal:3',
        'cess'           => 'decimal:3',
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'is_active'      => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
