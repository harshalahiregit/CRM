<?php

namespace App\Models\Transport;

use App\Models\Customer\Client;
use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use App\Support\Sql\SqlDate;
use App\Support\Transport\TripStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * The Trip — Step 9's "central operational and economic object" (DB-002).
 *
 * trip_number is flagged IMMUTABLE in Step 11 FLD-006, so the model enforces it
 * rather than trusting every future caller: once issued, an attempt to change it
 * throws. A reference that can be rewritten is not a canonical identifier, and
 * BR-P0-001 makes correcting one an admin-only act, not an ordinary update.
 *
 * @property int         $tenant_id
 * @property int         $order_id
 * @property string      $trip_number
 * @property string      $status
 * @property string|null $approved_freight
 */
class TransportTrip extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'transport_trips';

    protected $fillable = [
        'tenant_id', 'order_id', 'trip_number', 'status', 'approved_freight',
        'currency', 'customer_id', 'route', 'created_by', 'updated_by',
        // vehicle_id and driver_id are deliberately NOT fillable. They belong to
        // SNG-TRN-009 (allocation); leaving them mass-assignable would let this
        // ticket's endpoints set them with none of that ticket's eligibility
        // checks — precisely the "no invalid/blocked allocation" rule (TRP-P0-003).
    ];

    protected $casts = [
        'approved_freight' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function (TransportTrip $trip) {
            if ($trip->isDirty('trip_number')) {
                throw new RuntimeException(
                    'trip_number is immutable once issued (Step 11 FLD-006). Correcting one is an admin action, not an update.'
                );
            }
        });
    }

    /* ── Relations ──────────────────────────────────────────────────── */

    public function order(): BelongsTo
    {
        return $this->belongsTo(TransportOrder::class, 'order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'customer_id');
    }

    /* ── Scopes. Composed AFTER forTenant(), never instead of it. ────── */

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /** Trips a workspace would consider live — used by list filters only. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', TripStatus::OPEN);
    }

    public function scopeForOrder(Builder $query, int $orderId): Builder
    {
        return $query->where('order_id', $orderId);
    }

    /* ── Numbering fallback ─────────────────────────────────────────── */

    /**
     * TRP-YYYY-NNNNNN from the highest number already issued this year.
     *
     * BR-P0-001 requires uniqueness within company/YEAR; scoping the LIKE to
     * this year's prefix is what delivers that, and the unique index on
     * (tenant_id, trip_number) — not this method — is what guarantees it. Two
     * simultaneous creates can still read the same MAX; the caller retries on a
     * unique violation, the same contract HrEmployee::nextEmployeeCode states.
     */
    public static function nextLocalNumber(int $tenantId): string
    {
        $prefix = 'TRP-'.date('Y').'-';

        $highest = static::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('trip_number', 'like', $prefix.'%')
            ->selectRaw('MAX('.SqlDate::intCast('SUBSTR(trip_number, ?)').') AS seq', [strlen($prefix) + 1])
            ->value('seq');

        return $prefix.str_pad((string) (((int) $highest) + 1), 6, '0', STR_PAD_LEFT);
    }

    /* ── Derived state ──────────────────────────────────────────────── */

    public function statusLabel(): string
    {
        return TripStatus::label($this->status);
    }

    public function canTransitionTo(string $status): bool
    {
        return TripStatus::canTransition($this->status, $status);
    }

    /** True while the trip still counts against its order as an active movement. */
    public function isOpen(): bool
    {
        return in_array($this->status, TripStatus::OPEN, true);
    }
}
