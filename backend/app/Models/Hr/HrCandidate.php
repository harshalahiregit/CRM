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
        // #15 — present employment. `current_designation`/`current_department`
        // MUST be listed: create() silently discards any key not whitelisted, so
        // an omission would leave every auto-fetched value on the floor.
        'current_company','current_designation','current_department',
        // #15 — the REFERENCE: who sent this candidate to us. `source` says how
        // they arrived; these say who. Both must be whitelisted or create()
        // discards them without a word.
        'referred_by_id','referred_by_name',
        'experience_years','source','stage',
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

    /**
     * Has the AI engine actually run for this candidate?
     *
     * This is the correct gate for "AI screening completed" — NOT `ai_score > 0`.
     * The engine withholds a numeric score when confidence is below the configured
     * floor, so a legitimately screened candidate can carry a null ai_score with a
     * recommendation of "Insufficient Data". Gating on the number stalled those
     * candidates in the pipeline for a reason that has nothing to do with them.
     *
     * Reads the mirror rather than querying air_candidate_scores, so list rendering
     * stays free of N+1. ScoreRecorder stamps `engine` on every recording.
     */
    public function hasAiScreening(): bool
    {
        $breakdown = $this->ai_breakdown;

        return is_array($breakdown) && ! empty($breakdown['engine']);
    }

    /** Did the engine publish a score? Null means withheld or never run — never zero. */
    public function isScored(): bool
    {
        return $this->publishedAiScore() !== null;
    }

    /**
     * The score, but ONLY if the AIR engine produced it.
     *
     * Rows written before the engine existed (seeded literals, or the removed
     * heuristics) still carry an ai_score with no `engine` stamp and no
     * air_candidate_scores row. Serving that number would make a list show "87%"
     * beside a detail page reporting "not scored" — the list-vs-detail disagreement
     * this engine was built to end. Every payload must read through here.
     */
    public function publishedAiScore(): ?int
    {
        return $this->hasAiScreening() && $this->ai_score !== null
            ? (int) $this->ai_score
            : null;
    }

    /**
     * #15 — the employee who referred this candidate, where the referrer is one
     * of ours. An external referrer has no row here and lives in
     * `referred_by_name` instead.
     */
    public function referredBy()
    {
        return $this->belongsTo(HrEmployee::class, 'referred_by_id');
    }

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
