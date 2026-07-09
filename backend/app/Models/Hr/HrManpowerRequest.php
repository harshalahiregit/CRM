<?php

namespace App\Models\Hr;

use App\Models\User;
use App\Support\Hr\ManpowerRequestStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HrManpowerRequest extends Model
{
    use HasFactory;

    protected $table = 'hr_manpower_requests';

    protected $fillable = [
        'tenant_id', 'requested_by', 'assigned_manager_id',
        // Extended hiring information
        'business_unit', 'department', 'project', 'location',
        'position_title', 'employee_level', 'experience_required',
        'number_of_posts', 'job_type', 'priority',
        'salary_min', 'salary_max', 'required_skills',
        'job_description', 'justification',
        'required_by_date', 'target_joining_date',
        // Workflow
        'status', 'rejection_reason', 'approved_by', 'approved_at', 'manager_notified_at',
        'submitted_at', 'converted_at', 'posted_at', 'closed_at', 'job_posting_id',
        // L1
        'l1_approver_id', 'l1_approved_at', 'l1_remarks', 'l1_status',
        // L2
        'l2_approver_id', 'l2_approved_at', 'l2_remarks', 'l2_status',
    ];

    protected $casts = [
        'required_by_date'    => 'date',
        'target_joining_date' => 'date',
        'required_skills'     => 'array',
        'salary_min'          => 'decimal:2',
        'salary_max'          => 'decimal:2',
        'approved_at'         => 'datetime',
        'manager_notified_at' => 'datetime',
        'submitted_at'        => 'datetime',
        'converted_at'        => 'datetime',
        'posted_at'           => 'datetime',
        'closed_at'           => 'datetime',
        'l1_approved_at'      => 'datetime',
        'l2_approved_at'      => 'datetime',
    ];

    protected $appends = ['status_label', 'workflow_stage'];

    /* ── Relationships ──────────────────────────────────────────────────── */

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignedManager()
    {
        return $this->belongsTo(User::class, 'assigned_manager_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** L1 — Department Head */
    public function l1Approver()
    {
        return $this->belongsTo(User::class, 'l1_approver_id');
    }

    /** L2 — Management */
    public function l2Approver()
    {
        return $this->belongsTo(User::class, 'l2_approver_id');
    }

    /** The Job Description / posting created when HR converts this request. */
    public function jobPosting()
    {
        return $this->belongsTo(HrJobPosting::class, 'job_posting_id');
    }

    public function approvalHistory()
    {
        return $this->hasMany(HrApprovalHistory::class, 'request_id')->latest();
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    public function isFullyApproved(): bool
    {
        return Status::isFullyApproved($this->status)
            && $this->l1_status === 'approved'
            && $this->l2_status === 'approved';
    }

    /** Both approvals done and HR has not yet converted it to a JD. */
    public function canConvertToJd(): bool
    {
        return $this->status === Status::READY_FOR_HR;
    }

    public function canPublish(): bool
    {
        return $this->status === Status::CONVERTED_TO_JD;
    }

    public function isDraft(): bool    { return $this->status === Status::DRAFT; }
    public function isRejected(): bool { return $this->status === Status::REJECTED; }

    public function isPending(): bool
    {
        return in_array($this->status, [Status::L1_PENDING, Status::L2_PENDING], true);
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function getWorkflowStageAttribute(): string
    {
        return match ($this->status) {
            Status::DRAFT              => 'Draft — not yet submitted',
            Status::L1_PENDING         => 'Waiting for Department Head (L1) approval',
            Status::L2_PENDING         => 'L1 approved — waiting for Management (L2) approval',
            Status::READY_FOR_HR       => 'Fully approved — in HR queue, ready to convert to JD',
            Status::CONVERTED_TO_JD    => 'Converted to Job Description — awaiting publish',
            Status::JOB_POSTED         => 'Job published — accepting candidates',
            Status::HIRING_IN_PROGRESS => 'Hiring in progress',
            Status::CLOSED             => 'Position closed',
            Status::REJECTED           => 'Rejected — ' . ($this->rejection_reason ?? ''),
            default                    => (string) $this->status,
        };
    }

    /* ── Scopes ─────────────────────────────────────────────────────────── */

    public function scopePendingForManager($query, $managerId)
    {
        return $query->where('status', Status::L1_PENDING)
                     ->where('assigned_manager_id', $managerId);
    }

    public function scopePendingL1($query)
    {
        return $query->where('status', Status::L1_PENDING);
    }

    public function scopePendingL2($query)
    {
        return $query->where('status', Status::L2_PENDING);
    }

    public function scopeFullyApproved($query)
    {
        return $query->where('status', Status::READY_FOR_HR);
    }

    /** Requests that have cleared both approvals and belong to the HR queue. */
    public function scopeHrQueue($query)
    {
        return $query->whereIn('status', Status::HR_QUEUE);
    }
}
