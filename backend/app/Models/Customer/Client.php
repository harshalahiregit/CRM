<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer/client — the company entity. Mirrors the legacy tblclients:
 * the company itself does not log in (that's a linked contact's job) and may
 * exist with zero contacts (bulk-imported marketing data).
 */
class Client extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'company', 'gst_number', 'phone', 'website', 'parent_company',
        'opening_balance', 'opening_balance_date', 'vendor_id', 'lead_id',
        'address', 'city', 'state', 'zip', 'country',
        'billing_street', 'billing_city', 'billing_state', 'billing_zip', 'billing_country',
        'shipping_street', 'shipping_city', 'shipping_state', 'shipping_zip', 'shipping_country',
        'social_links', 'foundation_date', 'dob', 'anniversary_date',
        'default_currency', 'default_language', 'show_primary_contact', 'active', 'added_by',
    ];

    protected $casts = [
        'social_links'         => 'array',
        'foundation_date'      => 'date',
        'dob'                  => 'date',
        'anniversary_date'     => 'date',
        'opening_balance'      => 'decimal:2',
        'opening_balance_date' => 'date',
        'show_primary_contact' => 'boolean',
        'active'               => 'boolean',
    ];

    /* ── Relationships ─────────────────────────────────────────── */
    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class);
    }

    public function primaryContact()
    {
        return $this->hasOne(ClientContact::class)->where('is_primary', true);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ClientGroup::class, 'client_group_client');
    }

    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'customer_admins')->withPivot('tenant_id')->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ClientNote::class)->latest();
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(ClientReminder::class)->orderBy('remind_at');
    }

    public function vaultEntries(): HasMany
    {
        return $this->hasMany(ClientVaultEntry::class)->latest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ClientAttachment::class)->latest();
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(ClientAddress::class)->latest();
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(ClientRecipient::class)->latest();
    }

    /* ── Address snapshot ──────────────────────────────────────── */
    /**
     * Billing/shipping columns to stamp onto sales documents at creation, and
     * to push retroactively when the user opts in on the edit form.
     */
    public function addressSnapshot(): array
    {
        return [
            'billing_street'   => $this->billing_street,
            'billing_city'     => $this->billing_city,
            'billing_state'    => $this->billing_state,
            'billing_zip'      => $this->billing_zip,
            'billing_country'  => $this->billing_country,
            'shipping_street'  => $this->shipping_street,
            'shipping_city'    => $this->shipping_city,
            'shipping_state'   => $this->shipping_state,
            'shipping_zip'     => $this->shipping_zip,
            'shipping_country' => $this->shipping_country,
        ];
    }

    /* ── Scopes ────────────────────────────────────────────────── */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeSearch($query, ?string $term)
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('company', 'like', "%{$term}%")
                ->orWhere('gst_number', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%");
        });
    }
}
