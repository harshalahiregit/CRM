<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HrCandidate extends Model
{
    use HasFactory;

    protected $table = 'hr_candidates';

    protected $fillable = [
        'tenant_id','job_posting_id','name','email','phone','location',
        'current_company','experience_years','source','stage',
        'linkedin_url','linkedin_data','resume_path','ai_score','ai_breakdown',
        'skills','notes','final_decision',
    ];

    protected $casts = [
        'linkedin_data'    => 'array',
        'ai_breakdown'     => 'array',
        'skills'           => 'array',
        'experience_years' => 'decimal:1',
    ];

    public function jobPosting()
    {
        return $this->belongsTo(HrJobPosting::class, 'job_posting_id');
    }

    public function interviewRounds()
    {
        return $this->hasMany(HrInterviewRound::class, 'candidate_id')->orderBy('scheduled_at');
    }

    public function offer()
    {
        return $this->hasOne(HrOffer::class, 'candidate_id');
    }

    public function onboarding()
    {
        return $this->hasOne(HrOnboarding::class, 'candidate_id');
    }
}
