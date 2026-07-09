<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

class HrOnboarding extends Model
{
    protected $table = 'hr_onboarding';

    protected $fillable = [
        'candidate_id','tenant_id','candidate_name','position','joining_date',
        'department','employee_code','reporting_manager_name',
        'step_doc_verification','step_joining_confirmed','step_emp_id_generated',
        'step_dept_assigned','step_manager_assigned','step_record_created',
        'document_checklist','status',
    ];

    protected $casts = [
        'joining_date'             => 'date',
        'step_doc_verification'    => 'boolean',
        'step_joining_confirmed'   => 'boolean',
        'step_emp_id_generated'    => 'boolean',
        'step_dept_assigned'       => 'boolean',
        'step_manager_assigned'    => 'boolean',
        'step_record_created'      => 'boolean',
        'document_checklist'       => 'array',
    ];

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }
}
