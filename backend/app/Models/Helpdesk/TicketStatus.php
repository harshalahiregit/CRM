<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TicketStatus extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'color', 'order', 'is_closed_status', 'sla_paused'];

    protected $casts = [
        'order'            => 'integer',
        'is_closed_status' => 'boolean',
        'sla_paused'       => 'boolean',
    ];
}
