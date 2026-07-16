<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class CommissionEntry extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'commission_entries';

    protected $fillable = [
        'tenant_id', 'rule_id', 'staff_id', 'source_type', 'source_id',
        'base_amount', 'commission_amount', 'status', 'payout_status',
        'approved_by', 'approved_at', 'paid_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'base_amount'       => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'approved_at'       => 'datetime',
        'paid_at'           => 'datetime',
    ];

    public function rule()
    {
        return $this->belongsTo(CommissionRule::class, 'rule_id');
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function source()
    {
        return $this->morphTo();
    }
}
