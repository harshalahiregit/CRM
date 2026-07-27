<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Exit Request (Exit Phase 2) with its approval lifecycle (Phase 3). Employee-
 * scoped separation record built on the Phase 1 masters — Exit Type is required,
 * Exit Policy optional (drives notice). Lifecycle: Draft → Submitted → Under
 * Review → Approved / Rejected, with Withdrawn from Draft/Submitted. Approved and
 * Rejected are immutable. Never hard-deleted. The Auditable trail doubles as the
 * request + approval timeline.
 */
class HrExitRequest extends Model
{
    use Auditable;

    protected $table = 'hr_exit_requests';

    public const DRAFT = 'Draft';
    public const SUBMITTED = 'Submitted';
    public const UNDER_REVIEW = 'Under Review';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';
    public const WITHDRAWN = 'Withdrawn';

    /** Statuses that can no longer be edited/withdrawn — terminal or in-approval. */
    public const LOCKED = [self::UNDER_REVIEW, self::APPROVED, self::REJECTED, self::WITHDRAWN];

    protected $fillable = [
        'tenant_id', 'employee_id', 'exit_type_id', 'exit_policy_id',
        'request_date', 'last_working_date', 'notice_start_date', 'notice_end_date', 'notice_days',
        'reason', 'employee_remarks', 'hr_remarks', 'attachment_path',
        'status', 'submitted_at', 'withdrawn_at',
        'review_started_at', 'reviewed_by', 'review_remarks', 'decided_at', 'decided_by', 'decision_remarks',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'request_date'      => 'date',
        'last_working_date' => 'date',
        'notice_start_date' => 'date',
        'notice_end_date'   => 'date',
        'notice_days'       => 'integer',
        'submitted_at'      => 'datetime',
        'withdrawn_at'      => 'datetime',
        'review_started_at' => 'datetime',
        'decided_at'        => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function exitType()
    {
        return $this->belongsTo(HrExitType::class, 'exit_type_id');
    }

    public function policy()
    {
        return $this->belongsTo(HrExitPolicy::class, 'exit_policy_id');
    }
}
