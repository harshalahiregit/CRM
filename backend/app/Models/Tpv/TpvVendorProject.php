<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §35 — an explicit vendor ↔ project association (TPV-local; project by name,
 * no cross-module FK). Records the vendor's engagement on a project with a role
 * and window, rather than inferring it from work packages.
 */
class TpvVendorProject extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_vendor_projects';

    public const STATUSES = ['Active', 'Completed', 'On_Hold', 'Terminated'];

    protected $fillable = [
        'tenant_id', 'vendor_id', 'project', 'site', 'role',
        'start_date', 'end_date', 'status', 'notes',
        // Shed requirement (the business builds industrial sheds).
        'shed_site_location', 'shed_length', 'shed_width', 'shed_height', 'shed_purpose',
        'shed_side_wall', 'shed_flooring', 'shed_gate_shutter_size', 'shed_footing_done', 'shed_office_toilet',
    ];

    protected $casts = [
        'start_date'         => 'date',
        'end_date'           => 'date',
        'shed_length'        => 'decimal:2',
        'shed_width'         => 'decimal:2',
        'shed_side_wall'     => 'boolean',
        'shed_flooring'      => 'boolean',
        'shed_footing_done'  => 'boolean',
        'shed_office_toilet' => 'boolean',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
