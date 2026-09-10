<?php

namespace Sire\Models;

use Sire\Models\Concerns\RecordsSireAudit;
use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SIRE — root cause analysis, one per issue.
 *
 * `category` is a real column because defect trends group by it. Contributing
 * factors and the five whys are json: ordered prose, displayed and never filtered.
 */
class RootCause extends Model
{
    use RecordsSireAudit;
    use BelongsToSireTenant;
    use SoftDeletes;

    public const CATEGORIES = [
        'code', 'design', 'requirements', 'data', 'configuration',
        'infrastructure', 'third_party', 'process', 'human_error',
        'testing_gap', 'unknown',
    ];

    protected $table = 'sire_root_causes';

    protected $guarded = ['id'];

    protected $casts = [
        'contributing_factors' => 'array',
        'five_whys'            => 'array',
        'confirmed_at'         => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'confirmed_by');
    }

    /** A confirmed RCA is the one that counts in reporting. */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function scopeConfirmed($query)
    {
        return $query->whereNotNull('confirmed_at');
    }
}
