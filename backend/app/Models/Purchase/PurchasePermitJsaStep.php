<?php

namespace App\Models\Purchase;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of a permit's Job Safety Analysis: an activity, the hazard it carries,
 * the control applied, and the risk that remains after it.
 *
 * Residual risk is per STEP, not per permit — the point of a JSA is that one
 * step can stay high-risk after controls while the rest are low, which a single
 * permit-level figure hides.
 */
class PurchasePermitJsaStep extends Model
{
    use HasFactory;

    protected $table = 'purchase_permit_jsa_steps';

    protected $fillable = [
        'tenant_id', 'permit_id', 'step_no',
        'activity', 'hazard', 'control', 'residual_risk',
    ];

    protected $casts = ['step_no' => 'integer'];

    public function permit()
    {
        return $this->belongsTo(PurchaseWorkPermit::class, 'permit_id');
    }
}
