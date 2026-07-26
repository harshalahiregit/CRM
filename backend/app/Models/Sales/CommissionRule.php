<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class CommissionRule extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'commission_rules';

    protected $fillable = [
        'tenant_id', 'name', 'basis', 'calc_type', 'rate',
        'conditions', 'applies_to', 'is_active', 'created_by',
    ];

    protected $casts = [
        'rate'       => 'decimal:2',
        'conditions' => 'array',
        'is_active'  => 'boolean',
    ];

    public function entries()
    {
        return $this->hasMany(CommissionEntry::class, 'rule_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
