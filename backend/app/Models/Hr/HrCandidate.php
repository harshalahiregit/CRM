<?php

namespace App\Models\Hr;

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
        'skills','notes','final_decision','whatsapp_opt_in','whatsapp_number',
        'current_ctc','expected_ctc','notice_period','applied_at',
    ];

    protected $casts = [
        'linkedin_data'    => 'array',
        'ai_breakdown'     => 'array',
        'skills'           => 'array',
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

    public function whatsappLogs()
    {
        return $this->hasMany(HrWhatsAppLog::class, 'candidate_id')->latest();
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
