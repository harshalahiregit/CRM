<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

class HrInterviewRound extends Model
{
    protected $table = 'hr_interview_rounds';

    protected $fillable = [
        'candidate_id','tenant_id','round_name','interviewer_name','interviewer_id',
        'scheduled_at','meet_link','status','result','notes',
        'technical_score','communication_score','problem_solving_score','overall_score',
        'email_sent_candidate','email_sent_interviewer','whatsapp_sent','calendar_event_created',
        'reminder_minutes',
    ];

    protected $casts = [
        'scheduled_at'            => 'datetime',
        'email_sent_candidate'    => 'boolean',
        'email_sent_interviewer'  => 'boolean',
        'whatsapp_sent'           => 'boolean',
        'calendar_event_created'  => 'boolean',
    ];

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }
}
