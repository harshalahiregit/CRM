<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Support\Hr\JobPostingStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HrJobPosting extends Model
{
    use Auditable, HasFactory;

    protected $table = 'hr_job_postings';

    protected $fillable = [
        'tenant_id','manpower_request_id','title','department','location','job_type','posting_type',
        'description','requirements','salary_from','salary_to',
        'number_of_openings','closing_date','published_at','status','sources','applicant_count','external_job_ids',
        'on_career_portal','career_published_at',
    ];

    protected $casts = [
        'closing_date'        => 'date',
        'published_at'        => 'datetime',
        'career_published_at' => 'datetime',
        'on_career_portal'    => 'boolean',
        'sources'             => 'array',
        'external_job_ids'    => 'array',
        'salary_from'         => 'decimal:2',
        'salary_to'           => 'decimal:2',
    ];

    protected $appends = ['status_label'];

    /* ── Relationships ──────────────────────────────────────────────────── */

    public function candidates()
    {
        return $this->hasMany(HrCandidate::class, 'job_posting_id');
    }

    /** The approved Manpower Request this posting was converted from. */
    public function manpowerRequest()
    {
        return $this->belongsTo(HrManpowerRequest::class, 'manpower_request_id');
    }

    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class, 'tenant_id');
    }

    /** Distribution-channel publications (Career Portal, LinkedIn, …). */
    public function publications()
    {
        return $this->hasMany(HrJobPublication::class, 'job_posting_id');
    }

    /** Interviews for this job's candidates (job → candidates → interview rounds). */
    public function interviews()
    {
        return $this->hasManyThrough(HrInterviewRound::class, HrCandidate::class, 'job_posting_id', 'candidate_id', 'id', 'id');
    }

    /** Offers for this job's candidates (job → candidates → offers). */
    public function offers()
    {
        return $this->hasManyThrough(HrOffer::class, HrCandidate::class, 'job_posting_id', 'candidate_id', 'id', 'id');
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function isLive(): bool
    {
        return Status::isLive($this->status);
    }

    /* ── Scopes ─────────────────────────────────────────────────────────── */

    public function scopeLive($query)
    {
        return $query->whereIn('status', Status::LIVE);
    }
}
