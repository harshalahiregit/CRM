<?php

namespace App\Console\Commands;

use App\Models\Inventory\Batch;
use App\Services\Inventory\ConfigService;
use App\Services\Inventory\InventoryNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The clock behind the expiry alerts.
 *
 * Low stock doesn't need a clock — it's edge-triggered the instant a posting
 * pushes an item below its reorder point. Expiry has no such event: a batch goes
 * from "fine" to "expiring" because a day passed, and nothing in the app is
 * watching. This is what watches.
 *
 * Runs per tenant, honours each tenant's own `expiry_alert_days` window, and is
 * safe to run repeatedly: `expiry_alerted_at` is the idempotency guard, so a
 * batch is announced once when it enters the window rather than every morning
 * until someone deals with it. A catch-up run after downtime sends one digest,
 * not one per missed day.
 */
class RunInventoryAlerts extends Command
{
    protected $signature = 'inventory:run-alerts {--tenant= : Only this tenant}';

    protected $description = 'Send the inventory expiry digest (in-app + email) for each tenant';

    public function handle(InventoryNotifier $notifier, ConfigService $config): int
    {
        $tenants = DB::table('inventory_batches')
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', (int) $t))
            ->distinct()->pluck('tenant_id');

        $sent = 0;

        foreach ($tenants as $tenantId) {
            $tenantId = (int) $tenantId;
            $days = (int) ($config->get($tenantId, 'expiry_alert_days') ?? 30);

            // A window of zero is how a tenant says "we don't track expiry" —
            // respect it rather than falling back to a default they turned off.
            if ($days <= 0) {
                continue;
            }

            // A batch whose expiry date was pushed back out of the window is a
            // new situation — drop the stamp so it can alert again if it
            // re-enters, instead of being silenced forever by one old digest.
            Batch::forTenant($tenantId)
                ->whereNotNull('expiry_alerted_at')
                ->whereDate('expiry_date', '>', now()->addDays($days)->toDateString())
                ->update(['expiry_alerted_at' => null]);

            $batches = Batch::forTenant($tenantId)
                ->with('product:id,name,sku')
                ->whereNotNull('expiry_date')
                ->where('remaining_qty', '>', 0)
                // Failed/quarantined stock is already held back deliberately —
                // its expiry isn't news anyone needs to act on.
                ->whereIn('quality_status', ['pending', 'passed'])
                ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString())
                ->whereNull('expiry_alerted_at')
                ->orderBy('expiry_date')
                ->get();

            if ($batches->isEmpty()) {
                continue;
            }

            $notifier->expiringBatches($tenantId, $batches, $days);

            // Stamped regardless of delivery outcome: the notifier swallow-logs
            // its failures, and re-sending the same digest daily because SMTP
            // hiccupped once is worse than missing it.
            Batch::whereIn('id', $batches->pluck('id'))->update(['expiry_alerted_at' => now()]);

            $sent++;
            $this->info("Tenant {$tenantId}: {$batches->count()} batch(es) within {$days} days.");
        }

        $this->info($sent ? "Expiry digest sent for {$sent} tenant(s)." : 'Nothing expiring.');

        return self::SUCCESS;
    }
}
