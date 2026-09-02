<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One activity inside a work package.
 *
 * The competency rule is per ACTIVITY, not per package: welding and scaffolding
 * inside one package demand different tickets, and a package-level requirement
 * would either over- or under-gate every worker on it.
 */
class PurchaseActivity extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_activities';

    protected $fillable = [
        'tenant_id', 'work_package_id', 'name', 'description',
        'required_competency', 'status', 'sort_order',
        'requires_permit', 'permit_type', 'hazard',
    ];

    protected $casts = [
        'requires_permit' => 'boolean',
        'sort_order'      => 'integer',
    ];

    public function workPackage()
    {
        return $this->belongsTo(PurchaseWorkPackage::class, 'work_package_id');
    }
}
