<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\CalculatesDocumentTotals;

class Estimate extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, CalculatesDocumentTotals;

    protected $fillable = [
        'tenant_id', 'reference', 'subject', 'client_id', 'project_id',
        'date', 'valid_until', 'currency', 'discount_type',
        'subtotal', 'tax_total', 'discount_total', 'total',
        'sale_agent', 'status',
        'address', 'city', 'state', 'country', 'zip',
        'adminnote', 'clientnote', 'terms', 'tags',
        'sent_at', 'created_by',
        'estimate_type', 'payment_received', 'payment_amount',
        'supply_type', 'discount_mode', 'discount_value',
        'billing_street', 'billing_city', 'billing_state', 'billing_zip', 'billing_country',
        'payment_date', 'converted_invoice_id',
    ];

    protected $casts = [
        'date'       => 'date',
        'valid_until'=> 'date',
        'sent_at'    => 'datetime',
        'subtotal'   => 'decimal:2',
        'tax_total'  => 'decimal:2',
        'discount_total' => 'decimal:2',
        'total'      => 'decimal:2',
        'payment_received' => 'boolean',
        'payment_amount'   => 'decimal:2',
        'payment_date'     => 'date',
    ];

    /* ── Number auto-generation ─────────────────────── */
    protected static function booted(): void
    {
        static::creating(function (Estimate $est) {
            if (empty($est->estimate_type)) {
                $est->estimate_type = 'proforma';
            }
            if (empty($est->reference)) {
                // Estimates and proforma invoices number independently (matching the
                // sidebar split), so each maps to its own engine document type:
                // 'estimate' (EST) already ships in the registry, 'proforma_invoice'
                // (PI) is registered by SalesNumberingServiceProvider. Falls back to
                // the local allocator until the tenant enables the type.
                $isEstimate = $est->estimate_type === 'estimate';

                $est->reference = \App\Support\Sales\DocumentNumber::allocate(
                    $isEstimate ? 'estimate' : 'proforma_invoice',
                    (int) $est->tenant_id,
                    fn () => static::nextLocalReference((int) $est->tenant_id, $isEstimate ? 'EST-' : 'PI-'),
                );
            }
        });
    }


    /**
     * EST-YYYY-NNN / PI-YYYY-NNN from the highest reference already issued this
     * year for that prefix.
     *
     * Derived from MAX(reference) rather than COUNT(*): counting rows let a
     * deleted estimate's number be reissued, and two concurrent creates read the
     * same count and collided on the UNIQUE index. withTrashed() because
     * soft-deleted rows still occupy that index.
     */
    protected static function nextLocalReference(int $tenantId, string $typePrefix): string
    {
        $prefix = $typePrefix.date('Y').'-';

        $highest = static::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('reference', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTR(reference, ?) AS INTEGER)) AS seq', [strlen($prefix) + 1])
            ->value('seq');

        return $prefix.str_pad((string) (((int) $highest) + 1), 3, '0', STR_PAD_LEFT);
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

    public function convertedInvoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'converted_invoice_id');
    }

    /* ── Helpers ─────────────────────────────── */
    public function recalcTotals(): void
    {
        $t = $this->computeDocumentTotals();

        $this->subtotal       = $t['subtotal'];
        $this->tax_total      = $t['tax_total'];
        $this->discount_total = $t['all_discounts'];
        $this->total          = $t['total'];
        $this->supply_type    = $this->computeSupplyType();
        $this->saveQuietly();
    }
}
