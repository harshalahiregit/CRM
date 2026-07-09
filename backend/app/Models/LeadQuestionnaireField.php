<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LeadQuestionnaireField extends Model
{
    use HasFactory;

    protected $fillable = [
        'questionnaire_id', 'label', 'field_type', 'options',
        'placeholder', 'is_required', 'sort_order',
    ];

    protected $casts = [
        'options'     => 'array',
        'is_required' => 'boolean',
    ];

    public function questionnaire()
    {
        return $this->belongsTo(LeadQuestionnaire::class, 'questionnaire_id');
    }
}
