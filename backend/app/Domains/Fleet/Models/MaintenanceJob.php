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

    public const STATUSES = ['open', 'in_progress', 'awaiting_parts', 'completed', 'cancelled'];

    /** States that still hold the vehicle in the workshop. */
    public const OPEN_STATES = ['open', 'in_progress', 'awaiting_parts'];

    protected $fillable = [
        'company_id',
        'job_card_number',
        'vehicle_id',
        'trip_id',
        'complaint',
        'diagnosis',
        'parts_cost',
        'labour_cost',
        'total_cost',
        'status',
        'is_safety_critical',
        'opened_at',
        'closed_at',
        'qc_passed',
        'released_by',
    ];

    protected $casts = [
        'company_id'  => 'integer',
        'vehicle_id'  => 'integer',
        'trip_id'     => 'integer',
        'parts_cost'  => 'decimal:2',
        'labour_cost' => 'decimal:2',
        'total_cost'  => 'decimal:2',
        'is_safety_critical' => 'boolean',
        'qc_passed'   => 'boolean',
        'opened_at'   => 'datetime',
        'closed_at'   => 'datetime',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
