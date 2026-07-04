<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DeliveryNote extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'delivery_notes';

    protected $fillable = [
        'tenant_id', 'number', 'invoice_id', 'client_id',
        'delivery_date', 'status',
        'shipping_address', 'shipping_city', 'shipping_state',
        'shipping_country', 'shipping_zip', 'note', 'created_by',
    ];

    protected $casts = ['delivery_date' => 'date'];

    protected static function booted(): void
    {
        static::creating(function (DeliveryNote $dn) {
            if (empty($dn->number)) {
                $count = static::where('tenant_id', $dn->tenant_id)->count() + 1;
                $dn->number = 'DN-' . str_pad($count, 3, '0', STR_PAD_LEFT);
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

    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
