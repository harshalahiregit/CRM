<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One row of a permit's Job Safety Analysis: activity → hazard → control. */
class PermitJsaStep extends Model
{
    use BelongsToTenant;

    protected $table = 'permit_jsa_steps';

    public const RISKS = ['Low', 'Medium', 'High'];

    protected $fillable = [
        'tenant_id', 'permit_id', 'step_no', 'activity', 'hazard', 'control', 'residual_risk',
    ];

    protected $casts = ['step_no' => 'integer'];

    public function permit()
    {
        return $this->belongsTo(WorkPermit::class, 'permit_id');
    }
}
