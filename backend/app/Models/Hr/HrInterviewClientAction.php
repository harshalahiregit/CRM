<?php

namespace App\Models\Hr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A company-side action on an interview (Confirm / Reschedule / Cancel). This is
 * a REQUEST/signal, not a mutation — the recruiter still executes the change via
 * InterviewService. The row is the auditable history; the interview's own audit
 * trail carries the timeline entry.
 */
class HrInterviewClientAction extends Model
{
    protected $table = 'hr_interview_client_actions';

    public const CONFIRMED = 'Confirmed';
    public const RESCHEDULE = 'Reschedule';
    public const CANCEL = 'Cancel';
    public const ACCEPTED = 'Accepted';

    protected $fillable = [
        'tenant_id', 'interview_id', 'candidate_id', 'company_id', 'submission_id',
        'action', 'preferred_date', 'preferred_time', 'reason', 'created_by',
    ];

    protected $casts = [
        'preferred_date' => 'date',
    ];

    public function interview()
    {
        return $this->belongsTo(HrInterviewRound::class, 'interview_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
