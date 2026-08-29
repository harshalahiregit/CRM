<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §7 Due-Diligence checklist for a vendor. Each named check carries a status;
 * the record rolls up to a single Cleared/Rejected outcome that risk-tier gating
 * and approval depth can read.
 */
class TpvDueDiligence extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_due_diligences';

    /** The named checks the doc lists. */
    public const CHECKS = [
        'company_verification', 'document_verification', 'licence_verification',
        'insurance_verification', 'background_check', 'reference_check',
        'previous_performance', 'incident_history', 'compliance_history',
    ];

    public const CHECK_STATES = ['Pending', 'Verified', 'Failed', 'Not_Applicable'];

    public const STATUSES = ['Pending', 'In_Progress', 'Cleared', 'Rejected'];

    protected $fillable = [
        'tenant_id', 'vendor_id',
        'company_verification', 'document_verification', 'licence_verification',
        'insurance_verification', 'background_check', 'reference_check',
        'previous_performance', 'incident_history', 'compliance_history',
        'findings', 'notes', 'status', 'verified_by', 'verified_at',
    ];

    protected $casts = [
        'findings'    => 'array',
        'verified_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Roll the individual checks up to an overall status. Any Failed check
     * rejects; all-verified (ignoring Not_Applicable) clears; anything touched
     * is In_Progress; otherwise Pending.
     */
    public function deriveStatus(): string
    {
        $states = array_map(fn ($c) => $this->{$c}, self::CHECKS);

        if (in_array('Failed', $states, true)) {
            return 'Rejected';
        }

        $actionable = array_filter($states, fn ($s) => $s !== 'Not_Applicable');
        if ($actionable !== [] && ! in_array('Pending', $actionable, true)) {
            return 'Cleared';
        }

        return in_array('Verified', $states, true) ? 'In_Progress' : 'Pending';
    }
}
