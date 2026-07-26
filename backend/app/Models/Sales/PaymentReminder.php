<?php

namespace App\Models\Sales;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentReminder extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'invoice_id', 'reminder_type', 'days_offset',
        'scheduled_for', 'sent_at', 'email_to', 'status',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'sent_at'       => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }
}
