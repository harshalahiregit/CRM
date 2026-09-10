<?php

namespace Sire\Console\Commands;

use Sire\AI\SireIssueIndexer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SIRE — keep the duplicate-detection index current.
 *
 * A SEPARATE command from sire:run-schedule, deliberately. That one belongs to
 * core; this one belongs to the AI layer. If core called an AI indexer, core would
 * depend on AI and deleting the Ai namespace would break the scheduler — the exact
 * coupling the layering exists to prevent.
 *
 *   Schedule::command('sire:index-issues')
 *       ->everyFifteenMinutes()->withoutOverlapping()->runInBackground();
 *
 * Remove that line and SIRE loses duplicate suggestions. It loses nothing else.
 *
 * Not a queued job: there is no queue worker in production.
 */
class IndexSireIssues extends Command
{
    protected $signature = 'sire:index-issues {--tenant= : One tenant only} {--limit=500} {--rebuild : Reindex everything, not just what changed}';

    protected $description = 'SIRE: refresh the issue token index used for duplicate detection';

    public function handle(SireIssueIndexer $indexer): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $limit = (int) $this->option('limit');

        try {
            if ($this->option('rebuild')) {
                // Deliberately not offered without a tenant: rebuilding every
                // tenant at once on a database shared by two deployments is not
                // something anyone should be able to do by accident.
                if ($tenantId === null) {
                    $this->error('--rebuild requires --tenant. Rebuilding every tenant at once is not offered.');

                    return self::FAILURE;
                }
                $cleared = \Sire\Models\IssueToken::query()->forTenant($tenantId)->delete();
                $this->info("Cleared {$cleared} token rows for tenant {$tenantId}.");
            }

            $result = $indexer->sweep($tenantId, $limit);

            $summary = sprintf(
                'sire:index-issues indexed=%d tenants=%d limit=%d%s',
                $result['indexed'], $result['tenants'], $limit,
                $tenantId ? " tenant={$tenantId}" : '',
            );

            // There is no failure alerting in this environment; a greppable line
            // per run is the whole observability story.
            Log::info($summary);
            $this->info($summary);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('sire:index-issues failed', ['tenant_id' => $tenantId, 'message' => $e->getMessage()]);
            $this->error('Indexing failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
