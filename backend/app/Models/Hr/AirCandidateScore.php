<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * The live job-fit score for one candidate against one job.
 *
 * Every `*_score` column is nullable and NULL carries meaning: the dimension had no
 * data to work with. It is never a synonym for zero — that distinction is the whole
 * point of the engine, and any code reading these columns must preserve it.
 *
 * The psychometric columns from the original AIR migration (talent_score,
 * culture_fit, retention_*, hire_probability …) belong to a separate engine and are
 * not written here.
 */
class AirCandidateScore extends Model
{
    protected $table = 'air_candidate_scores';

    protected $fillable = [
        'tenant_id', 'candidate_id', 'job_id',
        'skills_score', 'experience_score', 'education_score', 'location_score',
        'salary_score', 'notice_score', 'resume_score', 'screening_score',
        'interview_score', 'jd_score',
        'overall_score', 'confidence_level', 'recommendation',
        'dimension_details', 'applied_weights', 'risk_flags',
        'strengths', 'weaknesses', 'ai_summary',
        'scoring_config_id', 'scored_trigger', 'scored_at',
    ];

    protected $casts = [
        'dimension_details' => 'array',
        'applied_weights'   => 'array',
        'risk_flags'        => 'array',
        // `strengths` / `weaknesses` are TEXT on the original migration; casting to
        // array keeps the API shape consistent with dimension_details.
        'strengths'         => 'array',
        'weaknesses'        => 'array',
        'scored_at'         => 'datetime',
    ];

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    public function jobPosting()
    {
        return $this->belongsTo(HrJobPosting::class, 'job_id');
    }

    public function config()
    {
        return $this->belongsTo(AirScoringConfig::class, 'scoring_config_id');
    }

    /** The API payload every page consumes. One shape, one source. */
    public function toScorePayload(): array
    {
        return [
            'is_scored'      => $this->overall_score !== null,
            'overall_score'  => $this->overall_score !== null ? (int) $this->overall_score : null,
            'recommendation' => $this->recommendation,
            'confidence'     => $this->confidence_level !== null ? (int) $this->confidence_level : null,
            'dimensions'     => array_values($this->dimension_details ?? []),
            'strengths'      => $this->strengths ?? [],
            'weaknesses'     => $this->weaknesses ?? [],
            'risk_flags'     => $this->risk_flags ?? [],
            'summary'        => $this->ai_summary,
            'scored_at'      => $this->scored_at?->toIso8601String(),
        ];
    }

    /**
     * The score payload for a candidate — the ONE shape every surface consumes
     * (HR profile, candidate list, kanban, interview detail, client portal).
     *
     * Resolving it here rather than at each call site is what stops a second,
     * subtly-different contract appearing: this used to be assembled separately in
     * the controller and in CompanyPortalService, with different keys.
     */
    public static function payloadFor(HrCandidate $candidate): array
    {
        $row = static::query()
            ->where('tenant_id', $candidate->tenant_id)
            ->where('candidate_id', $candidate->id)
            ->where(fn ($q) => $candidate->job_posting_id === null
                ? $q->whereNull('job_id')
                : $q->where('job_id', $candidate->job_posting_id))
            ->first();

        return $row ? $row->toScorePayload() : static::unscoredPayload();
    }

    /** Shape returned for a candidate that has never been scored. */
    public static function unscoredPayload(): array
    {
        return [
            'is_scored'      => false,
            'overall_score'  => null,
            'recommendation' => null,
            'confidence'     => null,
            'dimensions'     => [],
            'strengths'      => [],
            'weaknesses'     => [],
            'risk_flags'     => [],
            'summary'        => null,
            'scored_at'      => null,
        ];
    }
}
