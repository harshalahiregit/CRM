<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person under a client. Holds the portal-login link (user_id → users row
 * with role=client) when the contact has been granted portal access; a
 * contact can also be login-less (imported record).
 */
class ClientContact extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'client_id', 'user_id', 'first_name', 'last_name',
        'email', 'phone', 'title', 'is_primary', 'active', 'email_notifications',
    ];

    protected $casts = [
        'is_primary'          => 'boolean',
        'active'              => 'boolean',
        'email_notifications' => 'array',
    ];

    protected $appends = ['name'];

    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
