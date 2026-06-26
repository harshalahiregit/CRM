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
        'number_of_posts','required_by_date','job_type','priority',
        'justification','status','rejection_reason','approved_by','approved_at','manager_notified_at',
    ];

    protected $casts = [
        'required_by_date' => 'date',
        'approved_at'      => 'datetime',
        'manager_notified_at' => 'datetime',
    ];

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

    public function approvalHistory()
    {
        return $this->hasMany(HrApprovalHistory::class, 'manpower_request_id')->latest();
    }

    /**
     * Check if current user can approve this request
     */
    public function canBeApprovedBy(User $user): bool
    {
        // Admin can approve anything
        if ($user->isAdmin()) {
            return true;
        }

        // Hiring manager can only approve requests assigned to them
        if ($user->role === 'hiring_manager') {
            return $this->assigned_manager_id === $user->id;
        }

        return false;
    }

    /**
     * Check if request is pending approval
     */
    public function isPending(): bool
    {
        return $this->status === 'Pending';
    }

    /**
     * Scope: Pending approval requests for a manager
     */
    public function scopePendingForManager($query, $managerId)
    {
        return $query->where('status', 'Pending')
                     ->where('assigned_manager_id', $managerId);
    }
}
