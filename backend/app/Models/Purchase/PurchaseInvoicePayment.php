<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Purchase\PurchasePaymentMode as Mode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseInvoicePayment extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'purchase_invoice_payments';

    protected $fillable = [
        'tenant_id','purchase_invoice_id','created_by','amount','payment_date','payment_mode','reference','notes',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'payment_date' => 'date',
    ];

    protected $appends = ['payment_mode_label'];

    public function invoice()
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getPaymentModeLabelAttribute(): string
    {
        return Mode::label($this->payment_mode);
    }
}
