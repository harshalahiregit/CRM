<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** #10 — a named, reusable selection of bank questions for a kind of round. */
class HrInterviewQuestionSet extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_interview_question_sets';

    protected $fillable = [
        'tenant_id', 'name', 'description', 'designation_id', 'round_name',
        'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function items()
    {
        return $this->hasMany(HrInterviewQuestionSetItem::class, 'set_id')->orderBy('sort_order');
    }

    public function designation()
    {
        return $this->belongsTo(HrDesignation::class, 'designation_id');
    }
}
