<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class HrHiringRequest extends Model
{
    use Auditable, HasFactory;

    protected $table = 'hr_hiring_requests';

    protected $fillable = [
        'tenant_id', 'external_company_id',
        'job_title', 'department', 'business_unit', 'employment_type', 'work_mode',
        'number_of_positions', 'experience_required', 'target_joining_date', 'education', 'location',
        'salary_min', 'salary_max', 'required_skills', 'job_description', 'priority',
        'status', 'source', 'submitted_by', 'submitter_name', 'submitter_email',
        'assigned_recruiter_id', 'reviewed_by', 'reviewed_at', 'review_notes',
        'rejection_reason', 'manpower_request_id', 'converted_at', 'tracking_token',
        'assigned_at', 'first_submitted_at', 'closed_at',
    ];

    protected static function booted(): void
    {
        // Every hiring request gets a public tracking token for the client portal.
        static::creating(function (self $request) {
            if (empty($request->tracking_token)) {
                $request->tracking_token = Str::random(48);
            }
        });
    }

    protected $casts = [
        'required_skills'    => 'array',
        'salary_min'         => 'decimal:2',
        'salary_max'         => 'decimal:2',
        'reviewed_at'        => 'datetime',
        'converted_at'       => 'datetime',
        'assigned_at'        => 'datetime',
        'first_submitted_at' => 'datetime',
        'closed_at'          => 'datetime',
        'target_joining_date' => 'date',
    ];

    public function externalCompany()
    {
        return $this->belongsTo(HrExternalCompany::class, 'external_company_id');
    }

    public function assignedRecruiter()
    {
        return $this->belongsTo(User::class, 'assigned_recruiter_id');
    }

    public function manpowerRequest()
    {
        return $this->belongsTo(HrManpowerRequest::class, 'manpower_request_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function submissions()
    {
        return $this->hasMany(HrCandidateSubmission::class, 'hiring_request_id')->latest('id');
    }

    public function recruiterNotes()
    {
        return $this->hasMany(HrRecruiterNote::class, 'hiring_request_id')->latest('id');
    }

    public function clientRating()
    {
        return $this->hasOne(HrClientRating::class, 'hiring_request_id');
    }

    public function resumeShares()
    {
        return $this->hasMany(HrResumeShare::class, 'hiring_request_id')->latest('id');
    }

    public function messages()
    {
        return $this->hasMany(HrHiringRequestMessage::class, 'hiring_request_id');
    }

    public function documents()
    {
        return $this->hasMany(HrHiringRequestDocument::class, 'hiring_request_id')->latest('id');
    }
}
