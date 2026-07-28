<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-level scoring configuration — the only place weights and recommendation
 * thresholds live. Nothing in the engine, and nothing in the frontend, may hardcode
 * either.
 *
 * Resolution is tenant + job_family, falling back to the tenant default. The
 * psychometric weight columns from the original AIR migration are left alone.
 */
class AirScoringConfig extends Model
{
    protected $table = 'air_scoring_config';

    /** Dimension keys, in the order the engine reports them. */
    public const DIMENSIONS = [
        'skills', 'experience', 'jd', 'interview', 'education',
        'location', 'salary', 'notice', 'screening', 'resume',
    ];

    /** Used when a tenant has no row yet — mirrors the migration's column defaults. */
    public const DEFAULT_WEIGHTS = [
        'skills' => 25, 'experience' => 20, 'jd' => 15, 'interview' => 12,
        'education' => 8, 'location' => 6, 'salary' => 5, 'notice' => 4,
        'screening' => 3, 'resume' => 2,
    ];

    protected $fillable = [
        'tenant_id', 'job_family', 'is_active', 'is_default', 'pass_threshold',
        'skills_weight', 'experience_weight', 'jd_weight', 'interview_weight',
        'education_weight', 'location_weight', 'salary_weight', 'notice_weight',
        'screening_weight', 'resume_weight',
        'highly_recommended_threshold', 'recommended_threshold', 'consider_threshold',
        'min_confidence',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_default' => 'boolean',
    ];

    /** Weight per dimension key, as an integer map. */
    public function weights(): array
    {
        $out = [];
        foreach (self::DIMENSIONS as $d) {
            $out[$d] = (int) ($this->{$d.'_weight'} ?? self::DEFAULT_WEIGHTS[$d]);
        }

        return $out;
    }

    public function thresholds(): array
    {
        return [
            'highly_recommended' => (int) ($this->highly_recommended_threshold ?? 90),
            'recommended'        => (int) ($this->recommended_threshold ?? 75),
            'consider'           => (int) ($this->consider_threshold ?? 60),
            // Must match the column default (see the raise-min-confidence migration):
            // an unsaved config used as a fallback has to behave like a stored one.
            'min_confidence'     => (int) ($this->min_confidence ?? 60),
        ];
    }
}
