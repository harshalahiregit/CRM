<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Purchase\PurchaseVendor;
use App\Support\Purchase\PurchaseKickoffStatus as Status;
use App\Support\Purchase\PurchaseMeetingTypeCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Purchase-vendor meeting. Purchase-owned (purchase_kickoff_meetings) —
 * completely independent of the shared/TPV kickoff engine. Not polymorphic: it
 * always belongs to a Purchase vendor (purchase_vendor_id → purchase_vendors).
 *
 * "Kickoff" is now ONE configurable meeting_type among many (Sangoe TPV §9 / §39),
 * not a separate concept — the table name is legacy.
 */
class PurchaseKickoffMeeting extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_kickoff_meetings';

    protected $fillable = [
        'tenant_id', 'created_by', 'purchase_vendor_id', 'purchase_onboarding_id',
        'reference', 'title', 'meeting_type', 'agenda', 'status',
        'scheduled_at', 'duration_minutes', 'mode', 'location',
        'original_scheduled_at', 'delay_reason',
        'minutes', 'completed_at',
        'ack_token', 'acknowledged_at', 'acknowledged_by_name', 'acknowledged_ip',
        'meeting_platform', 'meeting_link', 'meeting_id', 'meeting_passcode', 'meeting_host_link',
    ];

    protected $casts = [
        'scheduled_at'          => 'datetime',
        'original_scheduled_at' => 'datetime',
        'completed_at'          => 'datetime',
        'acknowledged_at'       => 'datetime',
        'duration_minutes'      => 'integer',
    ];

    /** The ack link is a bearer credential — never leak it in list/show payloads. */
    protected $hidden = ['ack_token'];

    protected $appends = ['status_label', 'is_acknowledged', 'meeting_type_label'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function onboarding()
    {
        return $this->belongsTo(PurchaseOnboarding::class, 'purchase_onboarding_id');
    }

    public function participants()
    {
        return $this->hasMany(PurchaseKickoffParticipant::class, 'purchase_kickoff_meeting_id');
    }

    public function momDocuments()
    {
        return $this->hasMany(PurchaseKickoffMom::class, 'purchase_kickoff_meeting_id');
    }

    /** The current MOM PDF, if any. */
    public function currentMom()
    {
        return $this->hasOne(PurchaseKickoffMom::class, 'purchase_kickoff_meeting_id')->where('is_current', true);
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function getMeetingTypeLabelAttribute(): string
    {
        return PurchaseMeetingTypeCatalog::label($this->meeting_type);
    }

    public function getIsAcknowledgedAttribute(): bool
    {
        return $this->acknowledged_at !== null;
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', Status::OPEN);
    }
}
