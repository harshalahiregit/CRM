<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One approval level in an onboarding's approval chain.
 */
class OnboardingApproval extends Model
{
    use BelongsToTenant;

    protected $table = 'onboarding_approvals';

    protected $fillable = [
        'tenant_id', 'tpv_onboarding_id', 'level', 'approver_role', 'approver_id',
        'delegated_by', 'decision', 'remarks', 'sla_due_at', 'escalated_at', 'decided_at',
    ];

    protected $casts = [
        'level'        => 'integer',
        'sla_due_at'   => 'datetime',
        'escalated_at' => 'datetime',
        'decided_at'   => 'datetime',
    ];

    protected $appends = ['is_pending', 'is_overdue'];

    public function onboarding()
    {
        return $this->belongsTo(TpvOnboarding::class, 'tpv_onboarding_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->decision === null;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->is_pending && $this->sla_due_at !== null && $this->sla_due_at->isPast();
    }
}
