<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class HrInterviewRound extends Model
{
    use Auditable;

    protected $table = 'hr_interview_rounds';

    protected $fillable = [
        'candidate_id','tenant_id','round_name','mode','interviewer_name','interviewer_id','interviewers',
        'scheduled_at','meet_link','venue','status','result','recommendation','rating','notes',
        'technical_score','communication_score','problem_solving_score','overall_score',
        // Structured interviewer ratings (Doc 3 Module 6)
        'knowledge_score','confidence_score','ownership_score','learning_ability_score',
        'decision_making_score','leadership_score','integrity_score','culture_fit_score',
        'strengths','concerns',
        // Completion metadata (Complete-Interview form).
        'attendance','duration','remarks','completed_at','completed_by','cancellation_reason',
        'email_sent_candidate','email_sent_interviewer','whatsapp_sent','calendar_event_created',
        'reminder_minutes',
    ];

    protected $casts = [
        'scheduled_at'            => 'datetime',
        'completed_at'            => 'datetime',
        'interviewers'            => 'array',
        'rating'                  => 'integer',
        'duration'                => 'integer',
        'email_sent_candidate'    => 'boolean',
        'email_sent_interviewer'  => 'boolean',
        'whatsapp_sent'           => 'boolean',
        'calendar_event_created'  => 'boolean',
    ];

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    public function interviewer()
    {
        return $this->belongsTo(User::class, 'interviewer_id');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
