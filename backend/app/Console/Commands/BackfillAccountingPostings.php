<?php

namespace App\Console\Commands;

use App\Models\Accounts\AccountMapping;
use App\Models\Customer\ClientExpense;
use App\Models\Sales\CreditNote;
use App\Models\Sales\CreditNoteRefund;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesPayment;
use App\Services\Accounts\Integration\SalesPostingBridge;
use Illuminate\Console\Command;

/**
 * Posts historical (or previously-failed) Sales/Customer documents into the
 * Accounts ledger — the "convert existing" equivalent of the legacy CRM. Idempotent
 * (the bridge skips anything already posted), and only runs for tenants whose books
 * are set up. Best-effort per document; failures are counted, not fatal.
 */
class BackfillAccountingPostings extends Command
{
    protected $signature = 'accounts:backfill-postings {--tenant= : Limit to one tenant id}';

    protected $description = 'Post existing Sales/Customer documents into the Accounts ledger (idempotent)';

    public function handle(SalesPostingBridge $bridge): int
    {
        // Only tenants that have run accounts setup (mappings present).
        $tenantIds = AccountMapping::query()
            ->when($this->option('tenant'), fn ($q) => $q->where('tenant_id', $this->option('tenant')))
            ->distinct()->pluck('tenant_id');

        if ($tenantIds->isEmpty()) {
            $this->warn('No tenants with accounts set up. Nothing to backfill.');
            return self::SUCCESS;
        }

        $posted = 0; $skipped = 0; $failed = 0;
        $run = function (callable $fn) use (&$posted, &$skipped, &$failed) {
            try {
                $fn() ? $posted++ : $skipped++;
            } catch (\Throwable $e) {
                $failed++;
            }
        };

        foreach ($tenantIds as $tenantId) {
            SalesInvoice::forTenant($tenantId)->whereIn('status', ['Unpaid', 'Partially Paid', 'Paid', 'Overdue'])
                ->orderBy('date')->chunkById(200, fn ($rows) => $rows->each(fn ($m) => $run(fn () => $bridge->postInvoice($m))));

            CreditNote::forTenant($tenantId)->where('status', '!=', 'Void')
                ->orderBy('date')->chunkById(200, fn ($rows) => $rows->each(fn ($m) => $run(fn () => $bridge->postCreditNote($m))));

            SalesPayment::forTenant($tenantId)->where('payment_type', 'received')
                ->orderBy('date')->chunkById(200, fn ($rows) => $rows->each(fn ($m) => $run(fn () => $bridge->postReceipt($m))));

            CreditNoteRefund::query()
                ->whereIn('credit_note_id', CreditNote::forTenant($tenantId)->select('id'))
                ->orderBy('date')->chunkById(200, fn ($rows) => $rows->each(fn ($m) => $run(fn () => $bridge->postRefund($m))));

            ClientExpense::forTenant($tenantId)
                ->orderBy('date')->chunkById(200, fn ($rows) => $rows->each(fn ($m) => $run(fn () => $bridge->postExpense($m))));
        }

        $this->info("Backfill complete — posted: {$posted}, already-posted/skipped: {$skipped}, failed: {$failed}.");
        return self::SUCCESS;
    }
}
