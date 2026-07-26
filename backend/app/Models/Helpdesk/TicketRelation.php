<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TicketRelation extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'ticket_id', 'related_ticket_id'];
}
