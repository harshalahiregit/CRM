<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HrCandidate extends Model
{
    use HasFactory, Auditable;

    protected $table = 'hr_candidates';

    protected $fillable = [
        'tenant_id','job_posting_id','project_id','name','email','phone','dob','location','address',
        'current_company','experience_years','source','stage',
        'education','certifications','languages','professional_references',
        'linkedin_url','linkedin_data','resume_path','ai_score','ai_breakdown','screening_answers',
        'skills','notes','final_decision','whatsapp_opt_in','whatsapp_number',
        'current_ctc','expected_ctc','notice_period','applied_at',
        'assigned_recruiter_id',
    ];

    protected $casts = [
        'linkedin_data'    => 'array',
        'ai_breakdown'     => 'array',
        'screening_answers' => 'array',
        'skills'           => 'array',
        'dob'              => 'date',
        'education'        => 'array',
        'certifications'   => 'array',
        'languages'        => 'array',
        'professional_references' => 'array',
        'experience_years' => 'decimal:1',
        'current_ctc'      => 'decimal:2',
        'expected_ctc'     => 'decimal:2',
        'applied_at'       => 'datetime',
        'whatsapp_opt_in'  => 'boolean',
    ];

    public function jobPosting()
    {
        return $this->belongsTo(HrJobPosting::class, 'job_posting_id');
    }

    /** The Project this candidate belongs to (inherited from the Job Posting). */
    public function project()
    {
        return $this->belongsTo(\App\Models\Project\Project::class, 'project_id');
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

    /**
     * The employee record created when this candidate joined. Mirrors the lookup
     * the journey already performs — exposed as a relation so the profile can
     * eager-load Employee Status instead of querying per candidate.
     */
    public function employee()
    {
        return $this->hasOne(HrEmployee::class, 'candidate_id');
    }

    public function whatsappLogs()
    {
        return $this->hasMany(HrWhatsAppLog::class, 'candidate_id')->latest();
    }

    public function assignedRecruiter()
    {
        return $this->belongsTo(User::class, 'assigned_recruiter_id');
    }

    public function candidateNotes()
    {
        return $this->hasMany(HrCandidateNote::class, 'candidate_id')->with('user')->latest();
    }

    public function documents()
    {
        return $this->hasMany(HrCandidateDocument::class, 'candidate_id')->latest();
    }

    /**
     * Get the phone number to use for WhatsApp.
     */
    public function getWhatsAppNumber(): ?string
    {
        return $this->whatsapp_number ?? $this->phone;
    }

    /**
     * Check if candidate has opted in for WhatsApp.
     */
    public function canReceiveWhatsApp(): bool
    {
        return $this->whatsapp_opt_in && !empty($this->getWhatsAppNumber());
    }
}
