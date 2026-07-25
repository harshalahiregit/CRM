<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TicketTag extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'color'];

    public function tickets()
    {
        return $this->belongsToMany(Ticket::class, 'ticket_tag_pivot', 'tag_id', 'ticket_id')->withTimestamps();
    }
}
