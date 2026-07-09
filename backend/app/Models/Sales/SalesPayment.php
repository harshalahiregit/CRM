<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class SalesPayment extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'sales_payments';

    protected $fillable = [
        'tenant_id', 'invoice_id', 'date', 'amount',
        'mode', 'transaction_id', 'gateway', 'note', 'created_by',
    ];

    protected $casts = [
        'date'   => 'date',
        'amount' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
