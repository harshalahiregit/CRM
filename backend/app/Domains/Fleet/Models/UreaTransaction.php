<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * STOS-COST — one AdBlue / urea top-up.
 *
 * Its own table, not a flag on fuel_transactions: urea is a separate tank at a
 * separate price, and mixing it into diesel would corrupt every L/KM figure the
 * allocation ranking and the variance report depend on.
 *
 * Consumption is expressed as litres per 100 km rather than km/l — a truck uses
 * roughly 1.5 L of AdBlue per 100 km, and km-per-litre of urea is a number
 * nobody in a workshop thinks in.
 */
class UreaTransaction extends Model
{
    use BelongsToCompany;

    protected $table = 'urea_transactions';

    protected $fillable = [
        'company_id', 'vehicle_id', 'trip_id',
        'litres', 'rate_per_litre', 'amount', 'odometer',
        'litres_per_100km', 'station_vendor',
    ];

    protected $casts = [
        'company_id'       => 'integer',
        'vehicle_id'       => 'integer',
        'trip_id'          => 'integer',
        'litres'           => 'decimal:3',
        'rate_per_litre'   => 'decimal:2',
        'amount'           => 'decimal:2',
        'odometer'         => 'decimal:1',
        'litres_per_100km' => 'decimal:2',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
