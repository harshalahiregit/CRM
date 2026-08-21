<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A finding raised by a TPV inspection (Sangoe TPV §22). May escalate to an NCR. */
class TpvInspectionFinding extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_inspection_findings';

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
        return $this->belongsTo(TpvInspection::class, 'inspection_id');
    }

    public function ncr()
    {
        return $this->belongsTo(TpvNcr::class, 'ncr_id');
    }
}
