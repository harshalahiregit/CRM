<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** An emergency preparedness drill record (Doc_4 Phase 5/6). */
class EmergencyDrill extends Model
{
    use BelongsToTenant;

    protected $table = 'emergency_drills';

    public const TYPES = ['Fire', 'Evacuation', 'Medical', 'Spill', 'Other'];

    protected $fillable = [
        'tenant_id', 'conducted_by', 'drill_type', 'conducted_at',
        'location', 'participants', 'evacuation_seconds', 'findings',
    ];

    protected $casts = [
        'conducted_at'       => 'datetime',
        'participants'       => 'integer',
        'evacuation_seconds' => 'integer',
    ];

    public function conductor()
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }
}
