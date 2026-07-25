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

    /**
     * Per-contact module ACCESS permissions — kept SEPARATE from email
     * notifications (old-CRM `get_contact_permissions` set). `emails_enabled`
     * is the single master switch that mutes all mail regardless.
     */
    public const MODULES = [
        'invoice', 'estimate', 'contract', 'proposal', 'support', 'project',
    ];

    /** Per-type email-notification keys — independent of access permissions. */
    public const NOTIFICATION_KEYS = [
        'invoice', 'estimate', 'credit_note', 'proposal',
        'project', 'ticket', 'task', 'contract',
    ];

    protected $fillable = [
        'tenant_id', 'client_id', 'user_id', 'first_name', 'last_name',
        'email', 'phone', 'title', 'avatar', 'direction', 'is_primary', 'active',
        'email_notifications', 'permissions', 'emails_enabled',
        'password', 'last_password_change',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'is_primary'           => 'boolean',
        'active'               => 'boolean',
        'email_notifications'  => 'array', // legacy, read-only fallback
        'permissions'          => 'array',
        'emails_enabled'       => 'boolean',
        'password'             => 'hashed',
        'last_password_change' => 'datetime',
    ];

    /**
     * Single decision point for the mail layer: send a given notification
     * type to this contact only when the master switch is on AND that
     * notification is enabled. Access permissions are a separate concern.
     */
    public function wantsEmailsFor(string $type): bool
    {
        return $this->emails_enabled && (bool) ($this->email_notifications[$type] ?? false);
    }

    protected $appends = ['name', 'has_password'];

    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** Whether a portal password is set (the value itself is never exposed). */
    public function getHasPasswordAttribute(): bool
    {
        return ! empty($this->attributes['password']);
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
