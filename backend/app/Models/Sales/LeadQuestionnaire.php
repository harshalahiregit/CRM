<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class LeadQuestionnaire extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'title', 'description', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /* ── Relationships ─────────────────────── */
    public function fields()
    {
        return $this->hasMany(LeadQuestionnaireField::class, 'questionnaire_id')->orderBy('sort_order');
    }

    public function responses()
    {
        return $this->hasMany(LeadQuestionnaireResponse::class, 'questionnaire_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ── Scopes ────────────────────────────── */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
