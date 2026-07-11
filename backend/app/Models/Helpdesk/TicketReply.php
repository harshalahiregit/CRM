<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketReply extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'ticket_id', 'sender_type', 'sender_id',
        'message', 'cc', 'has_attachments',
    ];

    protected $casts = [
        'has_attachments' => 'boolean',
        'cc'              => 'array',
    ];

    /* ── Relationships ──────────────────────────────────────────── */
    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function attachments()
    {
        return $this->hasMany(TicketAttachment::class, 'reply_id');
    }

    // sender_id is polymorphic across users (admin/agent) and customers (client),
    // so it is intentionally left as a raw attribute — no morphTo, no cross-module FK.
}
