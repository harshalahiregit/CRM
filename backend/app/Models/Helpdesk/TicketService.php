<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A tenant-managed service a ticket can be classified under (Website, Software…). */
class TicketService extends Model
{
    use BelongsToTenant;

    protected $table = 'ticket_services';

    protected $fillable = ['tenant_id', 'name', 'order'];

    protected $casts = ['order' => 'integer'];
}
