<?php

namespace App\Models\Project;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'description', 'status', 'customer_id', 'vendor_user_id', 'vendor_id', 'link_type',
        'billing_type', 'project_cost', 'rate_per_hour', 'start_date', 'deadline',
        'progress', 'progress_from_tasks', 'estimated_hours', 'created_by', 'date_finished',
        'pinned_by', 'visible_tabs', 'customer_permissions', 'vendor_permissions', 'tpv_permissions', 'deadline_notified',
        'hide_tasks_on_main', 'send_created_email', 'contacts_notification',
    ];

    protected $casts = [
        'start_date'           => 'date',
        'deadline'             => 'date',
        'date_finished'        => 'datetime',
        'progress'             => 'integer',
        'progress_from_tasks'  => 'boolean',
        'project_cost'         => 'decimal:2',
        'rate_per_hour'        => 'decimal:2',
        'estimated_hours'      => 'decimal:2',
        'pinned_by'            => 'array',
        'visible_tabs'         => 'array',
        'customer_permissions' => 'array',
        'vendor_permissions'   => 'array',
        'tpv_permissions'      => 'array',
        'deadline_notified'    => 'boolean',
        'hide_tasks_on_main'   => 'boolean',
        'send_created_email'   => 'boolean',
    ];

    /** Who pinned this is an implementation detail — the UI only needs is_pinned. */
    protected $hidden = ['pinned_by'];

    /**
     * How a project records a TPV vendor link. Two spellings are live: rows
     * created before the vendor modules were split say 'tpv', rows created since
     * say 'tpv_vendor'. Both mean the same table (vendors), so both must match or
     * a vendor's older projects vanish from its screen.
     */
    public const TPV_LINK_TYPES = ['tpv', 'tpv_vendor'];

    /**
     * Purchase records exactly one spelling — the Purchase module was split out
     * after the two-spelling problem above, so it never accumulated a legacy
     * form. Kept as a one-element array so both parties resolve through the same
     * map rather than one being a special case.
     */
    public const PURCHASE_LINK_TYPES = ['purchase_vendor'];

    /**
     * vendor_type key → the link_type spellings it covers.
     *
     * FIXED, server-side. A caller names a party TYPE, never a raw link_type:
     * vendor_id is unique only within a link type, so accepting the raw value
     * would let one module's screen pivot onto another module's vendors — the
     * same integer is a different company under each.
     */
    public const VENDOR_LINK_MAP = [
        'tpv_vendor'      => self::TPV_LINK_TYPES,
        'purchase_vendor' => self::PURCHASE_LINK_TYPES,
    ];

    /* ── Scopes ─────────────────────────────────────────────────── */
    public function scopeStatus($query, ?string $status)
    {
        return $status ? $query->where('status', $status) : $query;
    }

    /**
     * Projects belonging to one TPV vendor.
     *
     * The single definition of that link, so the three screens hanging off it —
     * Projects, Tickets, Expenses — cannot drift apart. link_type is always
     * matched: vendor_id is only unique WITHIN a link type, so the same integer
     * is a different company under 'purchase_vendor'.
     *
     * Callers must scope the tenant themselves (forTenant) — this scope narrows
     * to a vendor, it does not authorise.
     */
    public function scopeForTpvVendor($query, int $vendorId)
    {
        return $query->forVendorLink($vendorId, 'tpv_vendor');
    }

    /**
     * Projects belonging to one vendor of a given party TYPE.
     *
     * The generalisation of scopeForTpvVendor, which now delegates here so both
     * parties share one definition and the three screens hanging off it —
     * Projects, Tickets, Expenses — cannot drift apart.
     *
     * An unrecognised $vendorType matches NOTHING rather than falling through to
     * every link type: a typo must return an empty tab, never another module's
     * projects.
     *
     * Callers must scope the tenant themselves (forTenant) — this narrows to a
     * vendor, it does not authorise.
     */
    public function scopeForVendorLink($query, int $vendorId, string $vendorType = 'tpv_vendor')
    {
        return $query->where('vendor_id', $vendorId)
            ->whereIn('link_type', self::VENDOR_LINK_MAP[$vendorType] ?? ['__none__']);
    }

    /* ── Relationships ──────────────────────────────────────────── */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members()
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function milestones()
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('order');
    }

    public function files()
    {
        return $this->hasMany(ProjectFile::class);
    }

    public function notes()
    {
        return $this->hasMany(ProjectNote::class)->latest();
    }

    public function activities()
    {
        return $this->hasMany(ProjectActivity::class)->latest();
    }

    // NOTE: no customer() relation — Customer belongs to Zafar's module. Resolve
    // customer data through CustomerServiceContract, never an Eloquent join here.
    // NOTE: tasks are polymorphic (rel_type='project', rel_id=id); queried in the
    // Task module rather than via a direct hasMany to avoid a hard cross-module bind.
}
