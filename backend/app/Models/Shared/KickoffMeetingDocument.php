<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One labelled file attached to a kickoff meeting. The store disk is the same
 * private 'kickoff_docs' disk the generated MoM uses.
 */
class KickoffMeetingDocument extends Model
{
    protected $fillable = [
        'tenant_id', 'kickoff_meeting_id', 'kickoff_mom_item_id', 'label',
        'original_name', 'path', 'mime', 'size', 'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    protected $appends = ['uploaded_by_name'];

    protected $hidden = ['path'];   // the storage path is never exposed to the client

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(KickoffMeeting::class, 'kickoff_meeting_id');
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
