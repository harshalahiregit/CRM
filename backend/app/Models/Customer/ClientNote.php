<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ClientNote extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'client_id', 'content', 'type', 'created_by',
        'priority', 'deadline', 'reminder_at', 'visibility',
        'contacted_at', 'is_pinned',
    ];

    protected $casts = [
        'deadline'     => 'date:Y-m-d',
        'reminder_at'  => 'datetime',
        // When the conversation happened, as distinct from when it was typed.
        'contacted_at' => 'datetime',
        'is_pinned'    => 'boolean',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
