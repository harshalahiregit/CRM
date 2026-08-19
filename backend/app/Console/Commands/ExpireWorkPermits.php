<?php

namespace App\Console\Commands;

use App\Services\Tpv\PermitService;
use Illuminate\Console\Command;

/** Expire work permits whose validity window has passed (Doc_4 Phase 5). */
class ExpireWorkPermits extends Command
{
    protected $signature = 'permits:expire {--tenant=}';

    protected $description = 'Mark work permits past their validity window as Expired';

    public function handle(PermitService $service): int
    {
        $n = $service->expireLapsed($this->option('tenant') ? (int) $this->option('tenant') : null);
        $this->info("Expired {$n} permit(s).");

        return self::SUCCESS;
    }
}
