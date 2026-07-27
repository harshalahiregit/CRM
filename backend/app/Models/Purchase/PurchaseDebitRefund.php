<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Purchase\PurchasePaymentMode as Mode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseDebitRefund extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'purchase_debit_refunds';

    protected $fillable = [
        'tenant_id','purchase_debit_note_id','created_by','amount','refund_date','refund_mode','reference','notes',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'refund_date' => 'date',
    ];

    protected $appends = ['refund_mode_label'];

    public function debitNote()
    {
        return $this->belongsTo(PurchaseDebitNote::class, 'purchase_debit_note_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getRefundModeLabelAttribute(): string
    {
        return Mode::label($this->refund_mode);
    }
}
