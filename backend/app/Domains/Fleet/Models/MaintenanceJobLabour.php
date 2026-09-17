<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * STOS-MAINT — one labour line on a job card (T-30).
 *
 * Kept separate from parts rather than folded into one `job_lines` table with a
 * type column: the two carry genuinely different facts. A part has a supplier
 * and a warranty; labour has a technician and hours, and hours are what feed
 * bay utilisation and the downtime figure. One table would leave half its
 * columns null in every row and force a discriminator into every query.
 */
class MaintenanceJobLabour extends Model
{
    use BelongsToCompany;

    protected $table = 'maintenance_job_labour';

    protected $fillable = [
        'company_id',
        'maintenance_job_id',
        'labour_type',
        'hours',
        'hourly_rate',
        'line_cost',
        'technician',
    ];

    protected $casts = [
        'company_id'         => 'integer',
        'maintenance_job_id' => 'integer',
        'hours'              => 'decimal:2',
        'hourly_rate'        => 'decimal:2',
        'line_cost'          => 'decimal:2',
    ];

    public function job()
    {
        return $this->belongsTo(MaintenanceJob::class, 'maintenance_job_id');
    }

    /** Hours x rate, at the scale money is stored in. */
    public static function lineCost($hours, $rate): string
    {
        return number_format((float) $hours * (float) $rate, 2, '.', '');
    }
}
