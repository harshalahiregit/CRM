<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A floor within an office. Seat capacity is informational, not enforced. */
class HrFloor extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_floors';

    protected $fillable = [
        'tenant_id', 'office_id', 'name', 'code', 'seat_capacity', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = ['is_active' => 'boolean', 'seat_capacity' => 'integer'];

    public function office()
    {
        return $this->belongsTo(HrOffice::class, 'office_id');
    }
}
