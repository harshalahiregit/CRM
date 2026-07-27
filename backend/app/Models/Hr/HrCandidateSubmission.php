<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single delivery of an existing candidate to a client, for a hiring request.
 *
 * This model owns only the client-review lifecycle (status + latest feedback);
 * it never duplicates candidate data — it references hr_candidates. Full
 * feedback history lives in audit_logs via the Auditable trait.
 */
class HrCandidateSubmission extends Model
{
    use Auditable, HasFactory;

    protected $table = 'hr_candidate_submissions';

    protected $fillable = [
        'tenant_id', 'hiring_request_id', 'candidate_id', 'status',
        'recruiter_note', 'client_feedback', 'feedback_at', 'submitted_by', 'submitted_at', 'viewed_at',
    ];

    protected $casts = [
        'feedback_at'  => 'datetime',
        'submitted_at' => 'datetime',
        'viewed_at'    => 'datetime',
    ];

    public function hiringRequest()
    {
        return $this->belongsTo(HrHiringRequest::class, 'hiring_request_id');
    }

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
