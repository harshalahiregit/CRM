<?php

namespace App\Console\Commands;

use App\Domains\Fleet\Services\ComplianceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * STOS-CMP — the nightly compliance sweep.
 *
 * Without this, a vehicle whose insurance expired at midnight still reads
 * "compliant" until somebody happens to edit it, and the pre-dispatch gate
 * waves it through. Dates pass with nobody touching the record, so the recompute
 * has to come to the data.
 */
class RefreshFleetCompliance extends Command
{
    protected $signature = 'stos:refresh-compliance {--company= : limit to one company}';

    protected $description = 'Recompute vehicle compliance verdicts from their document expiry dates';

    public function handle(ComplianceService $compliance): int
    {
        $companyId = $this->option('company') ? (int) $this->option('company') : null;

        $changed = $compliance->refreshAll($companyId);

        foreach ($changed as $row) {
            $this->line("  {$row['registration_number']}: {$row['from']} -> {$row['to']}");
        }

        $this->info(count($changed).' vehicle(s) changed compliance verdict.');

        if ($changed !== []) {
            Log::channel('stos')->info('Compliance sweep changed verdicts', [
                'company_id' => $companyId, 'changed' => $changed,
            ]);
        }

        return self::SUCCESS;
    }
}
