<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;

/** A pre-work toolbox talk / micro-training record (Doc_4 Phase 5/6). */
class ToolboxTalk extends Model
{
    use BelongsToTenant;

    protected $table = 'toolbox_talks';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'conducted_by', 'topic', 'held_at',
        'location', 'attendee_count', 'duration_minutes', 'notes',
    ];

    protected $casts = [
        'held_at'          => 'datetime',
        'attendee_count'   => 'integer',
        'duration_minutes' => 'integer',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function conductor()
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }
}
