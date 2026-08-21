<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * §10 — Customer Experience. One row per answered survey.
 *
 * CSAT and NPS live together because they answer the same management question
 * from two angles, and every screen that shows one wants the other beside it.
 * They are scored on different ranges, so anything comparing them must
 * normalise first — hence `normalisedScore()` rather than callers dividing by a
 * hard-coded maximum and getting NPS wrong.
 */
class ClientFeedback extends Model
{
    use BelongsToTenant;

    protected $table = 'client_feedback';

    public const CSAT = 'CSAT';
    public const NPS  = 'NPS';

    public const METRICS = [self::CSAT, self::NPS];

    /** Highest possible score per metric. CSAT is a 0-5 scale, NPS a 0-10 one. */
    public const MAX = [self::CSAT => 5, self::NPS => 10];

    protected $fillable = [
        'tenant_id', 'client_id', 'client_contact_id', 'metric', 'score', 'comments',
        'about_type', 'about_id', 'collected_via', 'responded_at', 'created_by',
    ];

    protected $casts = [
        'score'        => 'integer',
        'responded_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function contact()
    {
        return $this->belongsTo(ClientContact::class, 'client_contact_id');
    }

    /** 0-100, so a CSAT of 4/5 and an NPS of 8/10 are comparable. */
    public function normalisedScore(): float
    {
        $max = self::MAX[$this->metric] ?? 10;

        return $max > 0 ? round(($this->score / $max) * 100, 1) : 0.0;
    }

    /**
     * The standard NPS bucketing. Only meaningful for NPS rows — CSAT has no
     * promoter/detractor concept, so this returns null rather than inventing one.
     */
    public function npsBucket(): ?string
    {
        if ($this->metric !== self::NPS) {
            return null;
        }

        return match (true) {
            $this->score >= 9 => 'Promoter',
            $this->score >= 7 => 'Passive',
            default           => 'Detractor',
        };
    }
}
