<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Background verification record for an employee onboarding (Phase 4).
 * One row per onboarding; audited via the shared Auditable trait.
 */
class HrBackgroundVerification extends Model
{
    use Auditable;

    protected $table = 'hr_background_verifications';

    public const STATUSES = ['Pending', 'In Progress', 'Verified', 'Rejected'];

    protected $fillable = [
        'tenant_id', 'onboarding_id', 'employee_id',
        'vendor', 'reference_number', 'status', 'remarks', 'completed_date', 'updated_by',
    ];

    protected $casts = [
        'completed_date' => 'date',
    ];

    public function onboarding()
    {
        return $this->belongsTo(HrEmployeeOnboarding::class, 'onboarding_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
