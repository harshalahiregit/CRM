<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Holiday (Leave Phase 5). Tenant-scoped; reuses Org Setup dept/designation for scope. */
class HrHoliday extends Model
{
    use Auditable;

    protected $table = 'hr_holidays';

    public const TYPES = ['National', 'Festival', 'Company', 'Optional'];
    public const SCOPES = ['Organization', 'Department', 'Designation'];

    protected $fillable = [
        'tenant_id', 'title', 'description', 'holiday_date', 'holiday_type',
        'applicable_for', 'department_id', 'designation_id', 'is_optional', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'holiday_date' => 'date',
        'is_optional'  => 'boolean',
        'is_active'    => 'boolean',
    ];

    public function department()
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function designation()
    {
        return $this->belongsTo(HrDesignation::class, 'designation_id');
    }
}
