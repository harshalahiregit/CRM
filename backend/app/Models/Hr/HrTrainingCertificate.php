<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Training Certificate (L&D Phase 6) — issued for a completed training assignment.
 * Immutable once issued (only expiry state / file attachment change). Tenant-scoped,
 * audited.
 */
class HrTrainingCertificate extends Model
{
    use Auditable;

    protected $table = 'hr_training_certificates';

    public const ISSUED = 'Issued';
    public const EXPIRED = 'Expired';

    protected $fillable = [
        'tenant_id', 'employee_training_id', 'certificate_number', 'issue_date', 'expiry_date',
        'certificate_file', 'status', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'issue_date'  => 'date',
        'expiry_date' => 'date',
    ];

    public function assignment()
    {
        return $this->belongsTo(HrEmployeeTraining::class, 'employee_training_id');
    }
}
