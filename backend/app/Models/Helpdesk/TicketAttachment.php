<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketAttachment extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'reply_id', 'file_path', 'file_name',
    ];

    /* ── Relationships ──────────────────────────────────────────── */
    public function reply()
    {
        return $this->belongsTo(TicketReply::class, 'reply_id');
    }
}
