<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TicketDepartment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'description', 'order', 'manager_ids'];

    protected $casts = ['order' => 'integer', 'manager_ids' => 'array'];
}
