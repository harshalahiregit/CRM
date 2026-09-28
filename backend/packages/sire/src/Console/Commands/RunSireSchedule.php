<?php

namespace Sire\Console\Commands;

use Sire\Models\Report;
use Sire\Services\SireNotifier;
use Sire\Services\SireSlaService;
use Sire\Support\SireSlaState;
use Sire\Support\SireStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SIRE — the one scheduled command.
 *
 *   Schedule::command('sire:run-schedule')
 *       ->everyFifteenMinutes()->withoutOverlapping()->runInBackground();
 *
 * NOT a queued job. There is no queue worker in production — no supervisor, no
 * Horizon, no job-failure alerting — so anything dispatched would either run
 * inline on a request thread or sit in the jobs table forever.
 *
 * Four properties this command must keep:
 *
 *   1. IDEMPOTENT. A catch-up run after downtime must be a no-op, not a
 *      notification storm. Dedupe lives in sla_ack_notified_state /
 *      sla_resolve_notified_state, written in the same transaction as the send.
 *   2. TENANT-EXPLICIT. auth()->check() is false here, so nothing may rely on the
 *      BelongsToTenant auto-stamp. Every tenant is derived from the row.
 *   3. CHUNKED. chunkById, never ->get() over the register — the production
 *      database is shared with a second deployment.
 *   4. FAILURE-VISIBLE. There is no alerting, so it logs one greppable summary
 *      line per run and never lets one tenant's error abort the sweep.
 */
class RunSireSchedule extends Command
{
    protected $signature = 'sire:run-schedule {--tenant= : Sweep one tenant only} {--dry-run}';

    protected $description = 'SIRE: sweep SLA clocks and send warning/breach notices';

    public function handle(SireSlaService $sla, SireNotifier $notifier): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('tenant') ? (int) $this->option('tenant') : null;

        // tenant-sweep: deliberately unscoped — this is the query that DISCOVERS
        // which tenants to sweep. It selects tenant_id and nothing else; every
        // read of actual issue data below goes through forTenant().
        $tenants = Report::query()
            ->whereNotIn('status', SireStatus::TERMINAL)
            ->when($only, fn ($q) => $q->where('tenant_id', $only))
            ->select('tenant_id')->distinct()->pluck('tenant_id');

        $swept = 0;
        $warned = 0;
        $breached = 0;
        $errors = 0;

        foreach ($tenants as $tenantId) {
            try {
                [$s, $w, $b] = $this->sweepTenant((int) $tenantId, $sla, $notifier, $dryRun);
                $swept += $s;
                $warned += $w;
                $breached += $b;
            } catch (\Throwable $e) {
                // One tenant's bad data must not stop the other tenants' sweep.
                $errors++;
                Log::error('sire:run-schedule tenant failed', [
                    'tenant_id' => $tenantId,
                    'message'   => $e->getMessage(),
                ]);
            }
        }

        $summary = sprintf(
            'sire:run-schedule tenants=%d issues=%d warnings=%d breaches=%d errors=%d%s',
            $tenants->count(), $swept, $warned, $breached, $errors, $dryRun ? ' (dry run)' : '',
        );

        Log::info($summary);
        $this->info($summary);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{0:int,1:int,2:int} */
    private function sweepTenant(int $tenantId, SireSlaService $sla, SireNotifier $notifier, bool $dryRun): array
    {
        $swept = 0;
        $warned = 0;
        $breached = 0;

        Report::query()
            ->forTenant($tenantId)                       // opt-in scope: never omit
            ->whereNotIn('status', SireStatus::TERMINAL)
            ->whereNotNull('sla_started_at')
            ->with(['severity:id,code,ack_target_minutes,resolve_target_minutes', 'category:id,code'])
            ->chunkById(200, function ($reports) use ($sla, $notifier, $dryRun, &$swept, &$warned, &$breached) {
                foreach ($reports as $report) {
                    $swept++;
                    $result = $sla->for($report);

                    foreach (['ack', 'resolve'] as $clock) {
                        $state = $result[$clock]['state'] ?? null;

                        if (! in_array($state, [SireSlaState::WARNING, SireSlaState::BREACHED], true)) {
                            continue;
                        }
                        if ($dryRun) {
                            $state === SireSlaState::BREACHED ? $breached++ : $warned++;

                            continue;
                        }

                        // The dedupe write and the dispatch share a transaction, so
                        // a crash between them cannot produce a duplicate notice on
                        // the next run.
                        $sent = DB::transaction(
                            fn () => $notifier->sendSlaNotice($report, $clock, $state),
                        );

                        if ($sent) {
                            $state === SireSlaState::BREACHED ? $breached++ : $warned++;
                        }
                    }
                }
            });

        return [$swept, $warned, $breached];
    }
}
