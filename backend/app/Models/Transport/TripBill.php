<?php

namespace App\Models\Transport;

use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use App\Support\Transport\TripBillStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DB-012 `trip_bills` — the linkage between a trip and the invoice it will
 * become.  SNG-TRN-015.
 *
 * Owner in Step 11 is **Accounts**, and this model is deliberately the smaller
 * half of that ownership: Transport writes the row that says "this trip is
 * billable and worth this much", Accounts writes `invoice_id` when it posts the
 * invoice. Transport never posts one — FORBID-002 and LOCK-004, and EVT-010
 * `InvoicePosted` names Accounts as its producer.
 *
 * ── invoice_id IS NOT FILLABLE ───────────────────────────────────────────
 * The one column that belongs to the other module is the one a Transport
 * caller cannot mass-assign. Accounts sets it deliberately, through
 * markInvoiced(), which is the only door and says who walked through it.
 *
 * @property int         $tenant_id
 * @property int         $trip_id
 * @property int|null    $invoice_id
 * @property string      $status
 */
class TripBill extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'trip_bills';

    protected $fillable = [
        'tenant_id', 'trip_id', 'billable_amount', 'currency',
        'status', 'basis', 'prepared_by', 'prepared_at', 'notes',
        'created_by', 'updated_by',
        // invoice_id, invoiced_by and invoiced_at are NOT here — see above.
    ];

    protected $casts = [
        'trip_id'         => 'integer',
        'invoice_id'      => 'integer',
        // A decimal string, never a float. This figure is handed to Accounts to
        // raise an invoice from; FIN-06 blocks a release on float drift.
        'billable_amount' => 'decimal:2',
        'prepared_at'     => 'datetime',
        'invoiced_at'     => 'datetime',
    ];

    protected $appends = ['status_label'];

    /* ── Relations ────────────────────────────────────────────────────── */

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'trip_id');
    }

    /* ── Scopes. None filters by tenant; they compose AFTER forTenant(). ── */

    public function scopeForTrip(Builder $query, int $tripId): Builder
    {
        return $query->where('trip_id', $tripId);
    }

    /** What Accounts asks for on a schedule: billable, not yet invoiced. */
    public function scopeAwaitingInvoice(Builder $query): Builder
    {
        return $query->where('status', TripBillStatus::PREPARED)->whereNull('invoice_id');
    }

    /* ── The Accounts-side door ───────────────────────────────────────── */

    /**
     * Accounts has raised the invoice. The ONLY way invoice_id is ever set.
     *
     * Lives on the model rather than in a Transport service on purpose: the act
     * belongs to Accounts, and putting it behind a Transport service would
     * imply this module decides when an invoice exists. It does not.
     */
    public function markInvoiced(int $invoiceId, ?int $actorId = null): self
    {
        $this->forceFill([
            'invoice_id'  => $invoiceId,
            'status'      => TripBillStatus::INVOICED,
            'invoiced_by' => $actorId,
            'invoiced_at' => now(),
            'updated_by'  => $actorId,
        ])->save();

        return $this;
    }

    /* ── Derived ──────────────────────────────────────────────────────── */

    public function isInvoiced(): bool
    {
        return $this->invoice_id !== null;
    }

    public function getStatusLabelAttribute(): string
    {
        return TripBillStatus::label((string) $this->status);
    }
}
