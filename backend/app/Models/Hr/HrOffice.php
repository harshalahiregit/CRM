<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** An office within a branch. */
class HrOffice extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_offices';

    protected $fillable = [
        'tenant_id', 'branch_id', 'name', 'code', 'address', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function branch()
    {
        return $this->belongsTo(HrBranch::class, 'branch_id');
    }

    public function floors()
    {
        return $this->hasMany(HrFloor::class, 'office_id');
    }
}
