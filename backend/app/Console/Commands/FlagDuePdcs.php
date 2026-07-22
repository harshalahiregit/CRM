<?php

namespace App\Console\Commands;

use App\Models\Accounts\Cheque;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Flags post-dated cheques whose due date has arrived (spec §4.8 / §5.5). It does
 * NOT auto-post to the ledger — matching the legacy behaviour — it surfaces the
 * due PDCs (log now; a notification channel can hang off this later) so a user can
 * clear/deposit them. The "PDC due" list is also queryable from the UI.
 */
class FlagDuePdcs extends Command
{
    protected $signature = 'accounts:pdc-due';

    protected $description = 'Flag post-dated cheques that are due today or earlier';

    public function handle(): int
    {
        $due = Cheque::where('is_pdc', true)
            ->where('status', 'post_dated')
            ->whereDate('pdc_due_date', '<=', now()->toDateString())
            ->get();

        if ($due->isEmpty()) {
            $this->info('No post-dated cheques are due.');
            return self::SUCCESS;
        }

        foreach ($due->groupBy('tenant_id') as $tenantId => $cheques) {
            Log::channel('accounts')->info('PDCs due', [
                'tenant_id' => $tenantId,
                'count'     => $cheques->count(),
                'cheque_ids' => $cheques->pluck('id')->all(),
            ]);
        }

        $this->info("Flagged {$due->count()} due post-dated cheque(s) across {$due->groupBy('tenant_id')->count()} tenant(s).");
        return self::SUCCESS;
    }
}
