<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * STOS-COST — one FASTag toll crossing.
 *
 * These arrive by import, not by hand, and imports get re-run. The DB holds the
 * natural key (company + tag + instant) unique for that reason, so use
 * updateOrCreate() on it when ingesting a statement rather than create() —
 * otherwise a re-downloaded date range doubles the toll cost of every trip in it.
 *
 * `trip_id` has no relationship method: Dispatch owns Trip (golden rule 2).
 */
class FastagTransaction extends Model
{
    use BelongsToCompany;

    protected $table = 'fastag_transactions';

    public const RECONCILIATION_STATUSES = ['unreconciled', 'matched', 'disputed', 'settled'];

    protected $fillable = [
        'company_id',
        'vehicle_id',
        'trip_id',
        'tag_id',
        'plaza_name',
        'amount',
        'transaction_timestamp',
        'reconciliation_status',
    ];

    protected $casts = [
        'company_id'            => 'integer',
        'vehicle_id'            => 'integer',
        'trip_id'               => 'integer',
        'amount'                => 'decimal:2',
        'transaction_timestamp' => 'datetime',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
