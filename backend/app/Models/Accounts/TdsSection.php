<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A TDS section (194C/194J/…) with rate + threshold. Effective-dated. */
class TdsSection extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_tds_sections';

    protected $fillable = [
        'tenant_id', 'section_code', 'description', 'rate_percent', 'threshold',
        'effective_from', 'effective_to', 'is_active',
    ];

    protected $casts = [
        'rate_percent'   => 'decimal:3',
        'threshold'      => 'decimal:2',
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'is_active'      => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
