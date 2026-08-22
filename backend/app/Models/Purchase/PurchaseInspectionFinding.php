<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A finding raised by a Purchase inspection — mirror of TpvInspectionFinding (parity). May escalate to an NCR. */
class PurchaseInspectionFinding extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_inspection_findings';

    public const CATEGORIES = ['Observation', 'Non_Conformance', 'Positive'];

    public const SEVERITIES = ['Minor', 'Major', 'Critical'];

    public const STATUSES = ['Open', 'Action', 'Closed'];

    protected $fillable = [
        'tenant_id', 'inspection_id', 'description', 'category', 'severity', 'status',
        'corrective_action', 'due_date', 'responsible_by', 'ncr_id',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    public function inspection()
    {
        return $this->belongsTo(PurchaseInspection::class, 'inspection_id');
    }

    public function ncr()
    {
        return $this->belongsTo(PurchaseNcr::class, 'ncr_id');
    }
}
