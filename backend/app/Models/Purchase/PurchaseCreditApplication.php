<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One netting event: part of a debit note's balance applied against an invoice.
 *
 * Hard-deletable — removing the row reverses the netting (both balances restored),
 * the same way a payment or refund reverses. No soft-delete, so a reversed
 * application leaves no phantom sum behind on recalc.
 */
class PurchaseCreditApplication extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_credit_applications';

    protected $fillable = [
        'tenant_id','purchase_debit_note_id','purchase_invoice_id','created_by',
        'amount','applied_date','reference','notes',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'applied_date' => 'date',
    ];

    public function debitNote()
    {
        return $this->belongsTo(PurchaseDebitNote::class, 'purchase_debit_note_id');
    }

    public function invoice()
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
