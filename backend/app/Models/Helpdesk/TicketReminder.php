<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TicketReminder extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'ticket_id', 'user_id', 'remind_at', 'note', 'is_done'];

    protected $casts = [
        'remind_at' => 'datetime',
        'is_done'   => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
