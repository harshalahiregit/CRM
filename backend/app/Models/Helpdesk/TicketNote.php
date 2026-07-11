<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TicketNote extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'ticket_id', 'user_id', 'content'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
