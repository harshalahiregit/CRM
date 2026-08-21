<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Domain Manager (§1, §17 ADMIN) — the customer's domains we look after.
 *
 * The point of the screen is the expiry dates. A domain lapsing takes the
 * customer's site and mail down, so the useful behaviour is warning about it in
 * advance rather than recording the registrar's name.
 */
class ClientDomain extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUSES = ['Active', 'Expiring', 'Expired', 'Transferred', 'Cancelled'];

    /** How far ahead an expiry starts being worth mentioning. */
    public const EXPIRY_WARNING_DAYS = 30;

    protected $fillable = [
        'tenant_id', 'client_id', 'domain', 'registrar', 'registered_on', 'expires_on',
        'auto_renew', 'dns_provider', 'hosting_provider', 'ssl_expires_on', 'status',
        'notes', 'created_by',
    ];

    protected $casts = [
        'registered_on'  => 'date',
        'expires_on'     => 'date',
        'ssl_expires_on' => 'date',
        'auto_renew'     => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /** Negative once it has lapsed, so callers can tell "soon" from "already gone". */
    public function getDaysToExpiryAttribute(): ?int
    {
        return $this->expires_on
            ? (int) now()->startOfDay()->diffInDays($this->expires_on->startOfDay(), false)
            : null;
    }

    /**
     * Expiring within the warning window.
     *
     * Auto-renewing domains are still included: auto-renew fails often enough
     * (an expired card is the usual cause) that suppressing the warning is how
     * a domain gets lost.
     */
    public function scopeExpiringSoon($query, int $days = self::EXPIRY_WARNING_DAYS)
    {
        return $query->whereNotNull('expires_on')
            ->whereNotIn('status', ['Cancelled', 'Transferred'])
            ->whereDate('expires_on', '<=', now()->addDays($days)->toDateString());
    }
}
