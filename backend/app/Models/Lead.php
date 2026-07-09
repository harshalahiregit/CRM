<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'hash', 'name', 'title', 'company', 'email', 'phone',
        'website', 'description', 'lead_value', 'lead_score', 'lead_temperature',
        'conversion_chance', 'country', 'state', 'city', 'zip', 'address',
        'status_id', 'source_id', 'assigned_to', 'created_by', 'is_public',
        'last_contact_date', 'date_assigned', 'lost', 'junk', 'last_lead_status_id',
        'date_converted', 'client_id', 'lead_order', 'referral_type',
        'referral_value', 'referral_contact', 'tags',
    ];

    protected $casts = [
        'lead_value'        => 'decimal:2',
        'lead_score'        => 'integer',
        'conversion_chance' => 'integer',
        'is_public'         => 'boolean',
        'lost'              => 'boolean',
        'junk'              => 'boolean',
        'last_contact_date' => 'date',
        'date_assigned'     => 'datetime',
        'date_converted'    => 'datetime',
        'referral_value'    => 'decimal:2',
    ];

    /* ── Auto-generate hash on creation ──────────────────────── */
    protected static function booted(): void
    {
        static::creating(function (Lead $lead) {
            if (empty($lead->hash)) {
                $lead->hash = Str::random(32);
            }
            // Auto-assign default status if not set
            if (empty($lead->status_id) && $lead->tenant_id) {
                $default = LeadStatus::getDefault($lead->tenant_id);
                if ($default) {
                    $lead->status_id = $default->id;
                }
            }
        });

        // Recalculate score after every save
        static::saved(function (Lead $lead) {
            $lead->calcScoreQuietly();
        });
    }

    /* ── Relationships ─────────────────────── */
    public function status()
    {
        return $this->belongsTo(LeadStatus::class, 'status_id');
    }

    public function source()
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class)->latest();
    }

    public function notes()
    {
        return $this->hasMany(LeadNote::class)->latest();
    }

    public function proposals()
    {
        return $this->hasMany(Proposal::class, 'rel_id')->where('rel_type', 'lead');
    }

    public function questionnaireResponses()
    {
        return $this->hasMany(LeadQuestionnaireResponse::class);
    }

    /* ── Scopes ────────────────────────────── */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('lost', false)->where('junk', false);
    }

    public function scopeLost($query)
    {
        return $query->where('lost', true);
    }

    public function scopeJunk($query)
    {
        return $query->where('junk', true);
    }

    public function scopeConverted($query)
    {
        return $query->whereNotNull('date_converted');
    }

    public function scopeHot($query)
    {
        return $query->where('lead_temperature', 'Hot');
    }

    /* ── Lead Scoring Engine ───────────────────────────────────── */
    public function calcScore(): int
    {
        $score = 0;

        // Source scoring
        if ($this->source) {
            $sourceName = strtolower($this->source->name ?? '');
            if (in_array($sourceName, ['referral'])) $score += 20;
            elseif (in_array($sourceName, ['website', 'linkedin'])) $score += 15;
            elseif (in_array($sourceName, ['google', 'facebook'])) $score += 10;
            else $score += 5;
        }

        // Completeness scoring
        if (!empty($this->email))       $score += 10;
        if (!empty($this->phone))       $score += 10;
        if (!empty($this->company))     $score += 10;
        if (!empty($this->website))     $score += 5;
        if (!empty($this->title))       $score += 5;
        if (!empty($this->description)) $score += 5;

        // Value scoring
        if ($this->lead_value >= 100000) $score += 20;
        elseif ($this->lead_value >= 50000) $score += 15;
        elseif ($this->lead_value >= 10000) $score += 10;
        elseif ($this->lead_value > 0) $score += 5;

        // Engagement scoring
        $noteCount = $this->notes()->count();
        $score += min($noteCount * 5, 15); // max 15 from notes

        // Questionnaire completion
        if ($this->questionnaireResponses()->exists()) $score += 10;

        $score = min($score, 100); // cap at 100

        // Auto-categorize temperature
        $temp = $score > 70 ? 'Hot' : ($score >= 40 ? 'Warm' : 'Cold');

        $this->lead_score       = $score;
        $this->lead_temperature = $temp;
        $this->saveQuietly();

        return $score;
    }

    /**
     * Recalculate score without triggering the `saved` event loop.
     */
    private function calcScoreQuietly(): void
    {
        // Prevent infinite recursion — only calc if score is stale
        static $calculating = [];
        if (isset($calculating[$this->id])) return;
        $calculating[$this->id] = true;

        $this->calcScore();

        unset($calculating[$this->id]);
    }

    /* ── Lead Lifecycle Actions ────────────────────────────────── */
    public function markAsLost(int $performedBy): void
    {
        $this->last_lead_status_id = $this->status_id;
        $this->lost = true;
        $this->junk = false;
        $this->save();

        $this->logActivity('lost', 'Lead marked as lost', null, null, $performedBy);
    }

    public function markAsJunk(int $performedBy): void
    {
        $this->last_lead_status_id = $this->status_id;
        $this->junk = true;
        $this->lost = false;
        $this->save();

        $this->logActivity('junk', 'Lead marked as junk', null, null, $performedBy);
    }

    public function restoreFromLostJunk(int $performedBy): void
    {
        if ($this->last_lead_status_id) {
            $this->status_id = $this->last_lead_status_id;
        }
        $this->lost = false;
        $this->junk = false;
        $this->last_lead_status_id = null;
        $this->save();

        $this->logActivity('restored', 'Lead restored to active pipeline', null, null, $performedBy);
    }

    public function logActivity(string $type, string $description, ?string $oldVal = null, ?string $newVal = null, ?int $performedBy = null): void
    {
        $this->activities()->create([
            'tenant_id'    => $this->tenant_id,
            'type'         => $type,
            'description'  => $description,
            'old_value'    => $oldVal,
            'new_value'    => $newVal,
            'performed_by' => $performedBy ?? auth()->id(),
        ]);
    }
}
