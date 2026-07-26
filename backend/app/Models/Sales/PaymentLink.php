<?php

namespace App\Models\Sales;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentLink extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'invoice_id', 'amount', 'currency', 'status', 'token',
        'expiry_date', 'client_email', 'client_name', 'description',
        'payment_gateway', 'transaction_id', 'paid_at', 'created_by',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'expiry_date' => 'datetime',
        'paid_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentLink $link) {
            if (empty($link->token)) {
                $link->token = Str::random(40);
            }
        });
    }

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }
}
