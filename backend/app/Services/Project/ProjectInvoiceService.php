<?php

namespace App\Services\Project;

use App\Exceptions\BusinessException;
use App\Models\Project\Project;
use App\Models\Project\ProjectInvoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Invoice Project" — turn a project into a billable amount by its billing type.
 *
 *   • fixed          → the project's fixed cost, one line.
 *   • project_hours  → rate/hour × total hours logged across the project's tasks.
 *   • task_hours     → per billable task: the task's hourly rate × its logged hours.
 *
 * Honest by design: when the type yields nothing to bill (a $0 fixed rate, no
 * logged time, no billable tasks) it refuses with a clear reason rather than
 * writing a zero invoice — the same "produced nothing" outcome the spec notes.
 */
class ProjectInvoiceService
{
    public function list(int $projectId, int $tenantId): Collection
    {
        return ProjectInvoice::forTenant($tenantId)
            ->where('project_id', $projectId)
            ->with('creator:id,name')
            ->latest()
            ->get();
    }

    public function generate(int $projectId, int $tenantId, int $userId): ProjectInvoice
    {
        $project = Project::forTenant($tenantId)->find($projectId);
        if (! $project) {
            throw new BusinessException('Project not found.', 404);
        }

        [$lines, $amount] = $this->buildLines($project, $tenantId);

        if ($amount <= 0 || empty($lines)) {
            throw new BusinessException(
                'Nothing to invoice yet — this project has no billable amount for its billing type '
                .'(set a rate, log billable time, or mark tasks billable first).',
                422,
            );
        }

        $seq = ProjectInvoice::forTenant($tenantId)->where('project_id', $projectId)->count() + 1;

        return ProjectInvoice::create([
            'tenant_id'    => $tenantId,
            'project_id'   => $projectId,
            'number'       => sprintf('INV-%d-%03d', $projectId, $seq),
            'billing_type' => $project->billing_type,
            'amount'       => $amount,
            'currency'     => 'INR',
            'status'       => 'draft',
            'line_items'   => $lines,
            'created_by'   => $userId,
        ])->load('creator:id,name');
    }

    /** @return array{0: array<int,array<string,mixed>>, 1: float} [lines, total] */
    private function buildLines(Project $project, int $tenantId): array
    {
        return match ($project->billing_type) {
            'project_hours' => $this->projectHoursLines($project, $tenantId),
            'task_hours'    => $this->taskHoursLines($project, $tenantId),
            default         => $this->fixedLines($project),   // 'fixed'
        };
    }

    private function fixedLines(Project $project): array
    {
        $cost = round((float) $project->project_cost, 2);
        if ($cost <= 0) {
            return [[], 0.0];
        }

        return [[
            ['description' => 'Fixed project fee', 'qty' => 1, 'rate' => $cost, 'amount' => $cost],
        ], $cost];
    }

    private function projectHoursLines(Project $project, int $tenantId): array
    {
        $rate = round((float) $project->rate_per_hour, 2);
        $hours = round($this->loggedSeconds($tenantId, $project->id) / 3600, 2);
        $amount = round($rate * $hours, 2);

        if ($amount <= 0) {
            return [[], 0.0];
        }

        return [[
            ['description' => 'Logged hours', 'qty' => $hours, 'rate' => $rate, 'amount' => $amount],
        ], $amount];
    }

    private function taskHoursLines(Project $project, int $tenantId): array
    {
        if (! Schema::hasTable('task_timers') || ! Schema::hasTable('tasks')) {
            return [[], 0.0];
        }

        // Per billable task: its own hourly rate × the hours logged against it.
        $rows = DB::table('task_timers')
            ->join('tasks', 'tasks.id', '=', 'task_timers.task_id')
            ->where('tasks.tenant_id', $tenantId)
            ->where('tasks.rel_type', 'project')
            ->where('tasks.rel_id', $project->id)
            ->where('tasks.billable', true)
            ->whereNull('tasks.deleted_at')
            ->whereNotNull('task_timers.end_time')
            ->groupBy('tasks.id', 'tasks.name', 'tasks.hourly_rate')
            ->get([
                'tasks.id',
                'tasks.name',
                'tasks.hourly_rate',
                DB::raw('SUM('.$this->secondsExpr().') as seconds'),
            ]);

        $lines = [];
        $total = 0.0;
        foreach ($rows as $r) {
            $hours = round(((int) $r->seconds) / 3600, 2);
            $rate = round((float) $r->hourly_rate, 2);
            $amount = round($hours * $rate, 2);
            if ($amount <= 0) {
                continue;
            }
            $lines[] = ['description' => $r->name, 'qty' => $hours, 'rate' => $rate, 'amount' => $amount];
            $total += $amount;
        }

        return [$lines, round($total, 2)];
    }

    /** Total logged seconds across a project's tasks (completed timers only). */
    private function loggedSeconds(int $tenantId, int $projectId): int
    {
        if (! Schema::hasTable('task_timers') || ! Schema::hasTable('tasks')) {
            return 0;
        }

        return (int) DB::table('task_timers')
            ->join('tasks', 'tasks.id', '=', 'task_timers.task_id')
            ->where('tasks.tenant_id', $tenantId)
            ->where('tasks.rel_type', 'project')
            ->where('tasks.rel_id', $projectId)
            ->whereNull('tasks.deleted_at')
            ->whereNotNull('task_timers.end_time')
            ->sum(DB::raw($this->secondsExpr()));
    }

    /** Portable "seconds between start and end" SQL (driver-aware, like ProjectService). */
    private function secondsExpr(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite'           => "(strftime('%s', task_timers.end_time) - strftime('%s', task_timers.start_time))",
            'mysql', 'mariadb' => 'TIMESTAMPDIFF(SECOND, task_timers.start_time, task_timers.end_time)',
            'pgsql'            => 'EXTRACT(EPOCH FROM (task_timers.end_time - task_timers.start_time))',
            default            => "(strftime('%s', task_timers.end_time) - strftime('%s', task_timers.start_time))",
        };
    }
}
