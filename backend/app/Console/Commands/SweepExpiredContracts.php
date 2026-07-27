<?php

namespace App\Console\Commands;

use App\Services\Purchase\PurchaseContractService;
use Illuminate\Console\Command;

/**
 * Persist Active → Expired for purchase contracts past their end date.
 *
 * Read-time correctness is already handled by PurchaseContract::is_expired, but
 * persisting keeps stats/filters honest. Cron this nightly:
 *   php artisan contracts:sweep-expiry
 */
class SweepExpiredContracts extends Command
{
    protected $signature = 'contracts:sweep-expiry';

    protected $description = 'Mark Active purchase contracts past their end date as Expired';

    public function handle(PurchaseContractService $service): int
    {
        $count = $service->sweepExpired();
        $this->info("Expired {$count} contract(s).");

        return self::SUCCESS;
    }
}
