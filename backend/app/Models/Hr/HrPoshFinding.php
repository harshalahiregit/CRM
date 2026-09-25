<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * What the committee concluded, and what it recommends.
 *
 * Separate from the decision round that produced it. The round is the
 * authoritative record of who decided what and when; this is what the
 * committee wrote about that decision, and it has its own life — drafted while
 * it is being worked on, frozen when recorded, and published later as a
 * distinct act.
 *
 * PUBLICATION IS A TIMESTAMP, NOT A STATUS. A finding is recorded once and
 * published once; those are two different facts about the same row, and a
 * single status column would force one to overwrite the other.
 *
 * IMMUTABLE ONCE PUBLISHED. Nothing amends a published finding — not the
 * summary, not the recommendation, not the outcome. A correction would be a
 * new finding superseding this one, and that mechanism is deliberately not
 * built: it is a product decision about what a correction even means, and
 * guessing at it would put the guess in the record.
 */
class HrPoshFinding extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_posh_findings';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_RECORDED = 'recorded';

    protected $fillable = [
        'tenant_id', 'case_id', 'round_id', 'summary', 'recommendation',
        'status', 'outcome', 'recorded_at', 'recorded_by',
        'published_at', 'published_by', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'recorded_at'  => 'datetime',
        'published_at' => 'datetime',
    ];

    public function case()
    {
        return $this->belongsTo(HrPoshCase::class, 'case_id');
    }

    public function round()
    {
        return $this->belongsTo(HrDecisionRound::class, 'round_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isRecorded(): bool
    {
        return $this->status === self::STATUS_RECORDED;
    }

    /** Still being written, so still editable. */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT && ! $this->isPublished();
    }
}
