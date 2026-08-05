<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #26 — survey categories (Engagement, Exit, Pulse, Training Feedback…).
 *
 * A separate master from hr_training_categories on purpose: reusing the training
 * taxonomy would put "Fire Safety" in the survey category dropdown and force
 * every future training category to make sense as a survey one.
 */
class HrSurveyCategory extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_survey_categories';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'colour', 'description', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function surveys()
    {
        return $this->hasMany(HrSurvey::class, 'category_id');
    }
}
