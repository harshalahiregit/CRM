<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_bills';

    protected $fillable = [
        'tenant_id', 'voucher_id', 'vendor_ledger_id', 'vendor_name', 'bill_number',
        'bill_date', 'due_date', 'amount', 'status', 'paid_voucher_id', 'paid_date',
        'note', 'created_by',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'due_date'  => 'date',
        'paid_date' => 'date',
        'amount'    => 'decimal:2',
    ];

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function paidVoucher()
    {
        return $this->belongsTo(Voucher::class, 'paid_voucher_id');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'unpaid' && $this->due_date->isPast();
    }
}
