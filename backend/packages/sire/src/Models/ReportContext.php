<?php

namespace Sire\Models;

use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SIRE — the diagnostic sidecar for a report filed through Report Issue.
 *
 * Deliberately a separate table, not columns on sire_reports: the register list,
 * the dashboard and the 15-minute scheduled sweep all read sire_reports, and the
 * production database is shared by two deployments. Keeping a json blob and ten
 * diagnostic columns out of that table keeps those queries narrow.
 *
 * No SoftDeletes: this row has no independent life. It is deleted with its report.
 */
class ReportContext extends Model
{
    use BelongsToSireTenant;

    protected $table = 'sire_report_contexts';

    protected $fillable = [
        'tenant_id', 'report_id', 'url', 'browser', 'os', 'viewport',
        'locale', 'timezone', 'app_version', 'session_ref',
        'context_source', 'context_confidence', 'entity_source',
        'captured_at', 'failed_requests', 'page_context',
    ];

    protected $casts = [
        'captured_at'     => 'datetime',
        'failed_requests' => 'array',
        'page_context'    => 'array',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }
}
