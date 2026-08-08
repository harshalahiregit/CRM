<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class DeliveryNote extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

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
                // withTrashed(): soft-deleted rows still occupy the UNIQUE
                // index on `number`, so they must be counted or the next
                // create reuses a number and the insert fails.
                $count = static::withTrashed()->where('tenant_id', $dn->tenant_id)->count() + 1;
                $dn->number = 'DN-' . str_pad($count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    protected $appends = ['client'];

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The customer this document belongs to.
     *
     * Named `customer` because `client` is taken by the accessor below: the API has
     * always exposed `client` as a company-name STRING (the lists and detail pages
     * render it directly), and a relation of that name would serialise an object
     * there instead — breaking every one of those call sites.
     */
    public function customer()
    {
        return $this->belongsTo(\App\Models\Customer\Client::class, 'client_id');
    }

    /**
     * Company name for display.
     *
     * Appended because ~10 UI call sites read `.client` and nothing ever provided
     * it — the Client column on these lists rendered blank. Eager-load
     * `customer:id,company` when listing, or this lazy-loads per row.
     */
    public function getClientAttribute(): ?string
    {
        return $this->customer?->company;
    }
}
