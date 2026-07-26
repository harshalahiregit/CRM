<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Probation Confirmation (Probation Phase 5) — the confirmation decision on an
 * employee probation. Lifecycle: Pending → Approved → Confirmed, or Rejected.
 * Confirming closes the probation (marks it Confirmed). Reuses Employee /
 * Probation / Review / Extension — no duplicated data. Auditable trail is the
 * confirmation timeline.
 */
class HrProbationConfirmation extends Model
{
    use Auditable;

    protected $table = 'hr_probation_confirmations';

    public const PENDING = 'Pending';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';
    public const CONFIRMED = 'Confirmed';

    public const DECISIONS = ['Confirm', 'Extend', 'Terminate', 'Continue'];

    protected $fillable = [
        'tenant_id', 'probation_id', 'employee_id', 'latest_review_id', 'latest_extension_id',
        'recommendation', 'decision', 'confirmation_date', 'effective_date',
        'manager_comments', 'hr_comments', 'remarks',
        'approved_by', 'confirmed_by', 'approved_at', 'confirmed_at', 'status',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'confirmation_date' => 'date',
        'effective_date'    => 'date',
        'approved_at'       => 'datetime',
        'confirmed_at'      => 'datetime',
    ];

    public function probation()
    {
        return $this->belongsTo(HrEmployeeProbation::class, 'probation_id');
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function latestReview()
    {
        return $this->belongsTo(HrProbationReview::class, 'latest_review_id');
    }

    public function latestExtension()
    {
        return $this->belongsTo(HrProbationExtension::class, 'latest_extension_id');
    }
}
