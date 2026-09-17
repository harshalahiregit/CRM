<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * STOS-COST — one fill: diesel or AdBlue/urea.
 *
 * `amount` is the billed figure, stored, not derived from litres x rate — the
 * bill is the record and a rounding paisa is not a reason to disagree with it.
 * Every money cast is decimal, never float (golden rule 5).
 *
 * `trip_id` has no relationship method here on purpose: Trip is Dispatch's
 * model (Developer 1) and this domain does not reach into it (golden rule 2).
 * The column is the agreed hand-off point.
 */
class FuelTransaction extends Model
{
    use BelongsToCompany;

    protected $table = 'fuel_transactions';

    /**
     * Only meaningful for a fill someone has to answer for.
     *
     * 'billable' is the M2 addition: an emergency fill the customer carries,
     * which Developer 3's billing engine picks up. 'pending' stays for one we
     * recover from a driver or a hire partner instead.
     */
    public const RECOVERY_STATUSES = ['not_applicable', 'pending', 'billable', 'recovered', 'waived'];

    protected $fillable = [
        'company_id',
        'vehicle_id',
        'trip_id',
        'litres',
        'rate_per_litre',
        'amount',
        'odometer',
        'station_vendor',
        'is_emergency',
        'emergency_reason',
        'customer_recoverable',
        'receipt_path',
        'recovery_status',
        'km_driven',
        'efficiency_kmpl',
        'fuel_exception',
        'variance_note',
    ];

    protected $casts = [
        'company_id'     => 'integer',
        'vehicle_id'     => 'integer',
        'trip_id'        => 'integer',
        // Litres are not money: a pump prints 3 decimals and rounding them to 2
        // breaks reconciliation against the bill.
        'litres'         => 'decimal:3',
        'rate_per_litre' => 'decimal:2',
        'amount'         => 'decimal:2',
        'odometer'       => 'decimal:1',
        'is_emergency'   => 'boolean',
        'km_driven'      => 'decimal:1',
        'efficiency_kmpl' => 'decimal:2',
        'fuel_exception' => 'boolean',
        'customer_recoverable' => 'boolean',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
