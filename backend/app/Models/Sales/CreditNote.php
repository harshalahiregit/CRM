<?php

namespace App\Models\Sales;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditNote extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $table = 'credit_notes';

    protected $fillable = [
        'tenant_id', 'number', 'client_id', 'invoice_id',
        'date', 'currency', 'subtotal', 'tax_total', 'total', 'remaining',
        'reason', 'adminnote', 'clientnote', 'terms', 'status', 'created_by',
    ];

    protected $casts = [
        'date'      => 'date',
        'subtotal'  => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total'     => 'decimal:2',
        'remaining' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (CreditNote $cn) {
            if (empty($cn->number)) {
                $count = static::where('tenant_id', $cn->tenant_id)->count() + 1;
                $cn->number = 'CN-' . str_pad($count, 3, '0', STR_PAD_LEFT);
            }
            $cn->remaining = $cn->total;
        });
    }

    public function lineItems()
    {
        return $this->morphMany(SalesLineItem::class, 'lineable')->orderBy('sort_order');
    }

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    public function applications()
    {
        return $this->hasMany(CreditNoteApplication::class, 'credit_note_id');
    }

    public function refunds()
    {
        return $this->hasMany(CreditNoteRefund::class, 'credit_note_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recalcRemaining(): void
    {
        $applied  = $this->applications()->sum('amount');
        $refunded = $this->refunds()->sum('amount');
        $this->remaining = round($this->total - $applied - $refunded, 2);

        if ($this->remaining <= 0) {
            $this->status = 'Fully Applied';
        } elseif ($applied > 0 || $refunded > 0) {
            $this->status = 'Partially Applied';
        }

        $this->saveQuietly();
    }
}
