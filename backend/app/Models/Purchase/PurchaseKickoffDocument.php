<?php

namespace App\Models\Purchase;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One labelled file attached to a Purchase kickoff meeting (parity with the
 * shared KickoffMeetingDocument). Stored on the private purchase_kickoff_docs disk.
 */
class PurchaseKickoffDocument extends Model
{
    protected $fillable = [
        'tenant_id', 'purchase_kickoff_meeting_id', 'purchase_mom_action_item_id',
        'label', 'original_name', 'path', 'mime', 'size', 'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    protected $appends = ['uploaded_by_name'];

    protected $hidden = ['path'];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(PurchaseKickoffMeeting::class, 'purchase_kickoff_meeting_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'uploaded_by');
    }

    public function getUploadedByNameAttribute(): ?string
    {
        return $this->uploader?->name;
    }
}
