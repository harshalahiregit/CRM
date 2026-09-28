<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * STOS-MAINT — one replacement part on a job card (T-30).
 *
 * The workshop screen has always itemised parts; until this table existed the
 * lines were summed and thrown away, so "18,500" arrived at Finance with no
 * breakdown and a warranty claim six months later had nothing to stand on.
 *
 * `line_cost` is stored rather than computed on read for the same reason
 * `maintenance_jobs.total_cost` is: a signed job card is a document. If a unit
 * price is later corrected in a catalogue, what the card said at signing must
 * not silently change underneath it.
 */
class MaintenanceJobPart extends Model
{
    use BelongsToCompany;

    protected $table = 'maintenance_job_parts';

    protected $fillable = [
        'company_id',
        'maintenance_job_id',
        'part_name',
        'part_number',
        'quantity',
        'unit_cost',
        'line_cost',
        'supplier',
        'warranty_months',
    ];

    protected $casts = [
        'company_id'         => 'integer',
        'maintenance_job_id' => 'integer',
        'quantity'           => 'decimal:2',
        'unit_cost'          => 'decimal:2',
        'line_cost'          => 'decimal:2',
        'warranty_months'    => 'integer',
    ];

    public function job()
    {
        return $this->belongsTo(MaintenanceJob::class, 'maintenance_job_id');
    }

    /** Quantity x unit cost, at the scale money is stored in. */
    public static function lineCost($quantity, $unitCost): string
    {
        return number_format((float) $quantity * (float) $unitCost, 2, '.', '');
    }
}
