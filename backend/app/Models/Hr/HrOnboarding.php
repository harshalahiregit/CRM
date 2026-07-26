<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class HrOnboarding extends Model
{
    use Auditable;

    protected $table = 'hr_onboarding';

    /** Document types the candidate uploads during onboarding. */
    public const DOCUMENT_TYPES = ['aadhaar', 'pan', 'resume', 'photo', 'address_proof', 'educational_certificate', 'experience_document', 'medical', 'vaccination', 'cancelled_cheque', 'company_document', 'other'];

    protected $fillable = [
        'candidate_id','tenant_id','candidate_name','position','joining_date',
        'department','employee_code','reporting_manager_name',
        'step_doc_verification','step_joining_confirmed','step_emp_id_generated',
        'step_dept_assigned','step_manager_assigned','step_record_created',
        'document_checklist','status',
        // Candidate onboarding (Sprint 2)
        'access_token','submission','doc_verified','background_verified','medical_verified',
        'verification_status','verification_notes','rejection_reason',
        'invited_at','submitted_at','verified_at','joining_confirmed_at',
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
        'submission'               => 'array',
        'doc_verified'             => 'boolean',
        'background_verified'      => 'boolean',
        'medical_verified'         => 'boolean',
        'invited_at'               => 'datetime',
        'submitted_at'             => 'datetime',
        'verified_at'              => 'datetime',
        'joining_confirmed_at'     => 'datetime',
    ];

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    public function documents()
    {
        return $this->hasMany(HrOnboardingDocument::class, 'onboarding_id')->latest();
    }

    public function employee()
    {
        return $this->hasOne(HrEmployee::class, 'onboarding_id');
    }

    /** Onboarding verification is signed off and the candidate is offer-ready. */
    public function isApproved(): bool
    {
        return $this->verification_status === 'Approved';
    }
}
