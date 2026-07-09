<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class Estimate extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'reference', 'subject', 'client_id', 'project_id',
        'date', 'valid_until', 'currency', 'discount_type',
        'subtotal', 'tax_total', 'discount_total', 'total',
        'sale_agent', 'status',
        'address', 'city', 'state', 'country', 'zip',
        'adminnote', 'clientnote', 'terms', 'tags',
        'sent_at', 'created_by',
    ];

    protected $casts = [
        'date'       => 'date',
        'valid_until'=> 'date',
        'sent_at'    => 'datetime',
        'subtotal'   => 'decimal:2',
        'tax_total'  => 'decimal:2',
        'discount_total' => 'decimal:2',
        'total'      => 'decimal:2',
    ];

    /* ── Number auto-generation ─────────────────────── */
    protected static function booted(): void
    {
        static::creating(function (Estimate $est) {
            if (empty($est->reference)) {
                $year  = date('Y');
                $count = static::where('tenant_id', $est->tenant_id)
                               ->whereYear('created_at', $year)
                               ->count() + 1;
                $est->reference = 'EST-' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    /* ── Relationships ─────────────────────── */
    public function lineItems()
    {
        return $this->morphMany(SalesLineItem::class, 'lineable')->orderBy('sort_order');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'sale_agent');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ── Helpers ─────────────────────────────── */
    public function recalcTotals(): void
    {
        $subtotal = $taxTotal = $discountTotal = 0;
        foreach ($this->lineItems as $li) {
            $base         = $li->qty * $li->rate;
            $afterDis     = $base - $li->discount;
            $taxAmount    = $afterDis * ($li->tax / 100);
            $subtotal    += $base;
            $discountTotal+= $li->discount;
            $taxTotal    += $taxAmount;
        }
        $this->subtotal      = round($subtotal, 2);
        $this->tax_total     = round($taxTotal, 2);
        $this->discount_total= round($discountTotal, 2);
        $this->total         = round($subtotal - $discountTotal + $taxTotal, 2);
        $this->saveQuietly();
    }
}
