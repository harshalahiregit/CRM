<?php

namespace App\Models\Sales;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One email logged against a lead.
 *
 * `direction` exists so inbound mail can be recorded later without reshaping the
 * table; today only outbound is written, because that is all we can truthfully
 * observe without IMAP.
 */
class LeadEmail extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'lead_id', 'direction', 'to_email', 'from_email',
        'subject', 'body', 'status', 'error', 'sent_at', 'created_by',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
