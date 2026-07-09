<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class LeadQuestionnaireResponse extends Model
{
    use HasFactory, BelongsToTenant;

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

}
