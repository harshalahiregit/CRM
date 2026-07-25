<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketFeedback extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'ticket_feedback';

    protected $fillable = [
        'tenant_id', 'ticket_id', 'rating', 'comments',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    /* ── Relationships ──────────────────────────────────────────── */
    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }
}
