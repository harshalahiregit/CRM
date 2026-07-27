<?php

namespace App\Models\Hr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Internal recruiter note on a hiring request (Phase 3). Always private — only
 * exposed through the authenticated recruiter endpoints, never the client
 * portal. Mirrors HrCandidateNote's shape for a project-level context.
 */
class HrRecruiterNote extends Model
{
    protected $table = 'hr_recruiter_notes';

    protected $fillable = ['tenant_id', 'hiring_request_id', 'user_id', 'body'];

    public function hiringRequest()
    {
        return $this->belongsTo(HrHiringRequest::class, 'hiring_request_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
