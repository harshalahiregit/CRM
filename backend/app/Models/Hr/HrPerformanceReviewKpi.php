<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/** A single KPI rating within a performance review (PMS Phase 4). Name/weightage snapshotted. */
class HrPerformanceReviewKpi extends Model
{
    protected $table = 'hr_performance_review_kpis';

    protected $fillable = [
        'tenant_id', 'review_id', 'kpi_id', 'kpi_name', 'weightage', 'rating', 'comment',
    ];

    protected $casts = [
        'weightage' => 'decimal:2',
        'rating'    => 'decimal:2',
    ];
}
