<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An activity within a TPV work package (Sangoe TPV §13). `required_competency`
 * is the hook the Skill Matrix (Phase 5) uses to gate worker authorization.
 */
class TpvActivity extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_activities';

    public const STATUSES = ['Not_Started', 'In_Progress', 'Completed', 'On_Hold'];

    protected $fillable = [
        'tenant_id', 'work_package_id', 'name', 'description',
        'required_competency', 'status', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function workPackage()
    {
        return $this->belongsTo(TpvWorkPackage::class, 'work_package_id');
    }
}
