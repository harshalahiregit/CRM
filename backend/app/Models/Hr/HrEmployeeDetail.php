<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's personal, bank, identity and statutory detail.
 *
 * One row per employee, created on demand — most of these fields are blank for
 * most people most of the time, and a row of nulls for every employee is not
 * worth writing at hire.
 */
class HrEmployeeDetail extends Model
{
    use Auditable;

    protected $table = 'hr_employee_details';

    /**
     * Names match hr_employee_onboarding_profile so an onboarding carries over
     * by key. Keep them in step — a rename here silently stops that copy.
     */
    protected $fillable = [
        'tenant_id', 'employee_id',
        // Personal
        'marital_status', 'blood_group', 'father_name', 'mother_name', 'spouse_name',
        'nationality', 'religion', 'personal_email', 'alternate_phone',
        // Address (as on their documents; hr_employees.address is where they live)
        'permanent_address', 'permanent_city', 'permanent_state', 'permanent_pincode', 'permanent_country',
        // Education
        'highest_qualification', 'specialization', 'institution', 'year_of_passing',
        // Emergency contact
        'emergency_name', 'emergency_relationship', 'emergency_phone', 'emergency_alt_phone', 'emergency_address',
        // Bank
        'bank_account_holder_name', 'bank_account_number', 'bank_ifsc', 'bank_name', 'bank_branch', 'bank_account_type',
        // Identity
        'pan_number', 'aadhaar_number', 'passport_number', 'passport_expiry', 'driving_licence_number',
        // Statutory
        'uan_number', 'pf_number', 'esic_number', 'esic_ip_number',
        'pf_nominee_name', 'pf_nominee_relation',
        'is_international_worker', 'has_previous_pf', 'tax_regime',
    ];

    protected $casts = [
        'passport_expiry'         => 'date',
        'is_international_worker' => 'boolean',
        'has_previous_pf'         => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
