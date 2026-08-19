<?php

namespace App\Models\Vendor;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A persisted monthly VRS snapshot for one vendor (Doc 5). */
class VendorScorecard extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_scorecards';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'period',
        'safety_score', 'compliance_score', 'workforce_score', 'overall_score',
        'band', 'breakdown', 'computed_at',
    ];

    protected $casts = [
        'breakdown'   => 'array',
        'computed_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
