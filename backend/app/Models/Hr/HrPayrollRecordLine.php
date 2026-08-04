<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One frozen component line on a processed payroll record. */
class HrPayrollRecordLine extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_payroll_record_lines';

    protected $fillable = [
        'tenant_id', 'payroll_record_id', 'component_id', 'code', 'name', 'type',
        'source', 'amount', 'taxable', 'pf_applicable', 'esic_applicable', 'sort_order',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'taxable'         => 'boolean',
        'pf_applicable'   => 'boolean',
        'esic_applicable' => 'boolean',
    ];

    public function record()
    {
        return $this->belongsTo(HrPayrollRecord::class, 'payroll_record_id');
    }
}
