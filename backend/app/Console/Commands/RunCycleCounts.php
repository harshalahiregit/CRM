<?php

namespace App\Console\Commands;

use App\Models\Inventory\CountSession;
use App\Models\Inventory\Warehouse;
use App\Services\Inventory\ConfigService;
use App\Services\Inventory\CountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The clock behind cycle counting.
 *
 * An annual wall-to-wall stocktake is the count everyone plans and nobody
 * finishes: it needs the warehouse shut, so it slips, and by the time it runs
 * the numbers have been wrong for eleven months. A small RANDOM sample every
 * week is the opposite trade — it costs an hour, it happens, and because nobody
 * can predict which shelf gets looked at, nobody can keep one tidy for the audit
 * and let the rest rot.
 *
 * Off unless a tenant switches `cycle_count_auto` on. Raising sheets nobody
 * asked for would fill a working warehouse's queue with homework.
 *
 * Idempotent by design: a warehouse that already has an unfinished sheet is
 * skipped, so a catch-up run after downtime never stacks four weeks of counts
 * onto one person.
 */
class RunCycleCounts extends Command
{
    protected $signature = 'inventory:cycle-counts {--tenant= : Only this tenant}';

    protected $description = 'Raise this period\'s random cycle-count sheets for each warehouse';

    public function handle(CountService $counts, ConfigService $config): int
    {
        $tenants = DB::table('inventory_warehouses')
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', (int) $t))
            ->distinct()->pluck('tenant_id');

        $raised = 0;

        foreach ($tenants as $tenantId) {
            $tenantId = (int) $tenantId;

            if (! $config->get($tenantId, 'cycle_count_auto')) {
                continue;
            }

            $sample = max(1, (int) ($config->get($tenantId, 'cycle_count_sample') ?: 20));

            $warehouses = Warehouse::forTenant($tenantId)->where('status', 'active')->get();

            foreach ($warehouses as $warehouse) {
                // One open sheet at a time per site. Piling a second on top of an
                // unfinished one guarantees neither gets walked.
                $busy = CountSession::forTenant($tenantId)
                    ->where('warehouse_id', $warehouse->id)
                    ->whereNotIn('status', CountSession::CLOSED)
                    ->exists();

                if ($busy) {
                    $this->line("Tenant {$tenantId}, {$warehouse->name}: a count is still open — skipped.");
                    continue;
                }

                try {
                    $session = $counts->create([
                        'warehouse_id' => $warehouse->id,
                        'name'         => 'Cycle count '.now()->format('d M Y'),
                        'scope'        => 'random',
                        'sample_size'  => $sample,
                        // Assigned to the warehouse's manager when there is one;
                        // an unassigned sheet is one nobody owns.
                        'assigned_to'  => $warehouse->manager_id,
                        'note'         => 'Raised automatically.',
                        // Actor 0: nobody raised this, so the manager still gets
                        // told about it. Passing their own id would suppress the
                        // notice as self-notification and the sheet would appear
                        // in silence.
                    ], $tenantId, 0);

                    $raised++;
                    $this->info("Tenant {$tenantId}, {$warehouse->name}: {$session->code} raised.");
                } catch (\Throwable $e) {
                    // A site with no stock yet simply has nothing to count —
                    // not a failure worth stopping every other warehouse for.
                    $this->line("Tenant {$tenantId}, {$warehouse->name}: {$e->getMessage()}");
                }
            }
        }

        $this->info($raised ? "{$raised} cycle count(s) raised." : 'No cycle counts due.');

        return self::SUCCESS;
    }
}
