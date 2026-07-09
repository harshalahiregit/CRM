<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LeadQuestionnaireResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'questionnaire_id', 'lead_id', 'answers', 'submitted_at',
    ];

    protected $casts = [
        'answers'      => 'array',
        'submitted_at' => 'datetime',
    ];

    /* ── Relationships ─────────────────────── */
    public function questionnaire()
    {
        return $this->belongsTo(LeadQuestionnaire::class, 'questionnaire_id');
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    /* ── Scopes ────────────────────────────── */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
