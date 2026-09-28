<?php

namespace Sire\Models;

use Sire\Models\Concerns\BelongsToSireTenant;
use Sire\Dto\SireUserIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SIRE — one row per development pickup and per QA run.
 *
 * The report columns hold the CURRENT fix summary and QA notes. This table holds
 * what each round said, so a dev → QA → fail → dev → QA → pass round trip stays
 * legible after the second round overwrites the first. It is what the QA view
 * means by "related issue history".
 *
 * Never edited by a user. Rows are opened and closed by SireWorkflowService.
 */
class WorkCycle extends Model
{
    use BelongsToSireTenant;

    public const PHASE_DEVELOPMENT = 'development';
    public const PHASE_QA          = 'qa';

    protected $table = 'sire_work_cycles';

    protected $fillable = [
        'tenant_id', 'report_id', 'phase', 'cycle_no',
        'actor_id', 'started_at', 'ended_at', 'outcome', 'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
        'cycle_no'   => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'actor_id');
    }

    /** Open a cycle, closing any stale open one for the same phase first. */
    public static function open(Report $report, string $phase, SireUserIdentity $actor): self
    {
        self::abandon($report, $phase);

        $previous = self::query()
            ->forTenant($report->tenant_id)
            ->where('report_id', $report->id)
            ->where('phase', $phase)
            ->max('cycle_no');

        return self::create([
            // tenant_id from the report, never from ambient context — this can run
            // in a queue or a command where auth()->check() is false.
            'tenant_id'  => $report->tenant_id,
            'report_id'  => $report->id,
            'phase'      => $phase,
            'cycle_no'   => ((int) $previous) + 1,
            'actor_id'   => $actor->id,
            'started_at' => now(),
        ]);
    }

    public static function close(Report $report, string $phase, string $outcome, ?string $notes = null): ?self
    {
        $cycle = self::currentFor($report, $phase);

        if ($cycle === null) {
            return null;
        }

        $cycle->fill(['ended_at' => now(), 'outcome' => $outcome, 'notes' => $notes])->save();

        return $cycle;
    }

    /** Close whatever is open without a verdict — a pull-back or an unassign. */
    public static function abandon(Report $report, ?string $phase = null): void
    {
        self::query()
            ->forTenant($report->tenant_id)
            ->where('report_id', $report->id)
            ->when($phase, fn ($q) => $q->where('phase', $phase))
            ->whereNull('ended_at')
            ->update(['ended_at' => now(), 'outcome' => 'abandoned']);
    }

    public static function currentFor(Report $report, string $phase): ?self
    {
        return self::query()
            ->forTenant($report->tenant_id)
            ->where('report_id', $report->id)
            ->where('phase', $phase)
            ->whereNull('ended_at')
            ->latest('cycle_no')
            ->first();
    }
}
