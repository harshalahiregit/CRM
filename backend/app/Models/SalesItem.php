<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SalesItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'sales_items';

    protected $fillable = [
        'tenant_id', 'name', 'description', 'long_description',
        'rate', 'unit', 'tax_rate', 'tax_rate_2', 'category',
    ];

    protected $casts = [
        'rate'      => 'decimal:2',
        'tax_rate'  => 'decimal:2',
        'tax_rate_2'=> 'decimal:2',
    ];

    /* ── Scopes ─────────────────────────────── */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
