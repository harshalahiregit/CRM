<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ClientReminder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'client_id', 'description', 'remind_at',
        'remind_user_id', 'is_notified', 'created_by',
    ];

    protected $casts = [
        'remind_at'   => 'date',
        'is_notified' => 'boolean',
    ];

    public function assignee()
    {
        return $this->belongsTo(User::class, 'remind_user_id');
    }
}
