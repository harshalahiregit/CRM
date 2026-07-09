<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HrManpowerRequest extends Model
{
    use HasFactory;

    protected $table = 'hr_manpower_requests';

    protected $fillable = [
        'tenant_id','requested_by','assigned_manager_id','department','position_title',
        'number_of_posts','required_by_date','job_type','priority','justification',
        'status','rejection_reason','approved_by','approved_at','manager_notified_at',
        // L1
        'l1_approver_id','l1_approved_at','l1_remarks','l1_status',
        // L2
        'l2_approver_id','l2_approved_at','l2_remarks','l2_status',
    ];

    protected $casts = [
        'required_by_date'    => 'date',
        'approved_at'         => 'datetime',
        'manager_notified_at' => 'datetime',
        'l1_approved_at'      => 'datetime',
        'l2_approved_at'      => 'datetime',
    ];

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

    public function approvalHistory()
    {
        return $this->hasMany(HrApprovalHistory::class, 'request_id')->latest();
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    public function isFullyApproved(): bool
    {
        return $this->status === 'Approved' && $this->l1_status === 'approved' && $this->l2_status === 'approved';
    }

    public function canBePostedByHR(): bool
    {
        return $this->isFullyApproved();
    }

    public function isDraft(): bool  { return $this->status === 'Draft'; }
    public function isPending(): bool { return in_array($this->status, ['Pending_L1','Pending_L2','Pending']); }
    public function isRejected(): bool { return $this->status === 'Rejected'; }

    public function getWorkflowStageAttribute(): string
    {
        return match($this->status) {
            'Draft'      => 'Draft — not yet submitted',
            'Pending_L1' => 'Waiting for L1 (Department Head) approval',
            'Pending_L2' => 'L1 Approved — Waiting for L2 (Management) approval',
            'Approved'   => 'Fully Approved — HR can post the job',
            'Rejected'   => 'Rejected — ' . ($this->rejection_reason ?? ''),
            default      => $this->status,
        };
    }

    /* ── Scopes ─────────────────────────────────────────────────────────── */

    public function scopePendingForManager($query, $managerId)
    {
        return $query->where('status', 'Pending_L1')
                     ->where('assigned_manager_id', $managerId);
    }

    public function scopePendingL1($query)
    {
        return $query->where('status', 'Pending_L1');
    }

    public function scopePendingL2($query)
    {
        return $query->where('status', 'Pending_L2');
    }

    public function scopeFullyApproved($query)
    {
        return $query->where('status', 'Approved');
    }
}
