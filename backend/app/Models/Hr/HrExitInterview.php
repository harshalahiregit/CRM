<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HrExitInterview extends Model
{
    use Auditable, HasFactory;

    protected $table = 'hr_exit_interviews';

    protected $fillable = [
        'tenant_id', 'employee_id',
        'organization_or_project', 'personal_mobile', 'personal_email', 'exit_date',
        'reason_for_leaving', 'return_circumstances', 'recognition_feedback',
        'policies_feedback', 'jd_changed_feedback', 'tools_resources_feedback',
        'training_feedback', 'best_part', 'improvements', 'morale_suggestions',
        'looking_forward_to', 'ideal_replacement', 'would_recommend',
        'rating', 'status', 'submitted_at',
    ];

    protected $casts = [
        'exit_date'    => 'date',
        'submitted_at' => 'datetime',
        'rating'       => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
