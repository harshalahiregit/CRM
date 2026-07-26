<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ClientNote extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'client_id', 'content', 'created_by',
        'priority', 'deadline', 'reminder_at', 'visibility',
    ];

    protected $casts = [
        'deadline'    => 'date:Y-m-d',
        'reminder_at' => 'datetime',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
