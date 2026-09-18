<?php

namespace App\Domains\Fleet\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * STOS-MAINT — a workshop job card.
 *
 * `total_cost` is stored, not accessor-computed. A job card is a document that
 * gets signed off, and it may legitimately differ from parts + labour (a
 * discount, a warranty credit, a rounded settlement). The domain service decides
 * what it is; nothing recalculates it behind the workshop's back on read.
 */
class MaintenanceJob extends Model
{
    use BelongsToCompany;

    protected $table = 'maintenance_jobs';

    /**
     * T-32 — `testing` and `qc` sit between the bench work and the signature.
     *
     * They are not cosmetic: a card in `qc` has had its work done but has not
     * been cleared, and that is precisely the window in which somebody is
     * tempted to take the vehicle. Both are OPEN states for that reason.
     */
    public const STATUSES = ['open', 'in_progress', 'awaiting_parts', 'testing', 'qc', 'completed', 'cancelled'];

    /** States that still hold the vehicle in the workshop. */
    public const OPEN_STATES = ['open', 'in_progress', 'awaiting_parts', 'testing', 'qc'];

    /* T-31 — what QC actually said. */
    public const QC_PASS = 'PASS';
    public const QC_FAIL = 'FAIL';
    public const QC_CRITICAL_FAIL = 'CRITICAL_FAIL';

    public const QC_RESULTS = [self::QC_PASS, self::QC_FAIL, self::QC_CRITICAL_FAIL];

    protected $fillable = [
        'company_id',
        'job_card_number',
        'vehicle_id',
        'trip_id',
        'workshop_name',
        'complaint',
        'diagnosis',
        'parts_cost',
        'labour_cost',
        'total_cost',
        'status',
        'is_safety_critical',
        'opened_at',
        'closed_at',
        'downtime_hours',
        'qc_passed',
        'qc_result',
        'clears_job_id',
        'road_tested',
        'released_by',
    ];

    protected $casts = [
        'company_id'  => 'integer',
        'vehicle_id'  => 'integer',
        'trip_id'     => 'integer',
        'parts_cost'  => 'decimal:2',
        'labour_cost' => 'decimal:2',
        'total_cost'  => 'decimal:2',
        'downtime_hours' => 'decimal:2',
        'is_safety_critical' => 'boolean',
        'qc_passed'   => 'boolean',
        'clears_job_id' => 'integer',
        'road_tested' => 'boolean',
        'opened_at'   => 'datetime',
        'closed_at'   => 'datetime',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function parts()
    {
        return $this->hasMany(MaintenanceJobPart::class, 'maintenance_job_id');
    }

    public function labour()
    {
        return $this->hasMany(MaintenanceJobLabour::class, 'maintenance_job_id');
    }
}
