<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** KPI master (PMS Phase 3). Reusable performance indicators, tenant-scoped. */
class HrKpi extends Model
{
    use Auditable;

    protected $table = 'hr_kpis';

    protected $fillable = [
        'tenant_id', 'name', 'category', 'description', 'weightage', 'rating_scale', 'is_active',
    ];

    protected $casts = [
        'weightage'    => 'decimal:2',
        'rating_scale' => 'integer',
        'is_active'    => 'boolean',
    ];
}
