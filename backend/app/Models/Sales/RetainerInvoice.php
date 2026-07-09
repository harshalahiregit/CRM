<?php

namespace App\Models\Sales;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RetainerInvoice extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'client_id', 'number', 'amount', 'currency',
        'billing_period_start', 'billing_period_end', 'status',
        'retainer_type', 'auto_create', 'next_billing_date', 'created_by',
    ];

    protected $casts = [
        'amount'                => 'decimal:2',
        'billing_period_start'  => 'date',
        'billing_period_end'    => 'date',
        'auto_create'           => 'boolean',
        'next_billing_date'     => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (RetainerInvoice $ri) {
            if (empty($ri->number)) {
                $year  = date('Y');
                $count = static::where('tenant_id', $ri->tenant_id)
                               ->whereYear('created_at', $year)
                               ->count() + 1;
                $ri->number = 'RI-' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
