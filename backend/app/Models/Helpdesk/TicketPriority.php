<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TicketPriority extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'color', 'order', 'is_default', 'first_response_hours', 'resolution_hours'];

    protected $casts = [
        'order'                => 'integer',
        'is_default'           => 'boolean',
        'first_response_hours' => 'integer',
        'resolution_hours'     => 'integer',
    ];
}
