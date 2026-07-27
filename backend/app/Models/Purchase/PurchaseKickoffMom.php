<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A generated (or uploaded) Minutes-of-Meeting PDF for a Purchase kickoff meeting.
 * Append-only history — each generate/upload adds a row and flips is_current.
 * Purchase-owned (purchase_kickoff_mom, private `purchase_kickoff_docs` disk).
 */
class PurchaseKickoffMom extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_kickoff_mom';

    protected $fillable = [
        'tenant_id', 'purchase_kickoff_meeting_id', 'file_path', 'original_name',
        'source', 'is_current', 'generated_by', 'generated_at',
    ];

    protected $casts = [
        'is_current'   => 'boolean',
        'generated_at' => 'datetime',
    ];

    public function meeting()
    {
        return $this->belongsTo(PurchaseKickoffMeeting::class, 'purchase_kickoff_meeting_id');
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
