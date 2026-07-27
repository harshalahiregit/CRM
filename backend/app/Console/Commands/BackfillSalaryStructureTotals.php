<?php

namespace App\Console\Commands;

use App\Models\Hr\HrSalaryStructure;
use App\Services\Hr\SalaryFormulaEngine;
use Illuminate\Console\Command;

/**
 * One-time (idempotent) backfill of the denormalised salary-structure totals added
 * by the Enterprise Salary Engine. Structures created before those columns existed
 * carry 0s until re-saved; this recomputes gross / employer / CTC / deduction / net
 * for every structure via the central engine and persists the cache. Safe to re-run.
 */
class BackfillSalaryStructureTotals extends Command
{
    protected $signature = 'salary:backfill-structure-totals {--tenant= : Limit to one tenant id}';

    protected $description = 'Recompute and persist denormalised totals on salary structures';

    public function handle(SalaryFormulaEngine $engine): int
    {
        $updated = 0;
        HrSalaryStructure::with('lines.component')
            ->when($this->option('tenant') !== null, fn ($q) => $q->where('tenant_id', (int) $this->option('tenant')))
            ->chunkById(200, function ($structures) use ($engine, &$updated) {
                foreach ($structures as $structure) {
                    $items = $structure->lines->map(fn ($line) => [
                        'key'              => $line->id,
                        'code'             => $line->component->code ?? ('CMP'.$line->id),
                        'name'             => $line->component->name ?? '(removed)',
                        'type'             => $line->component->type ?? 'Earning',
                        'calculation_type' => $line->calculation_type ?: ($line->component->calculation_type ?? 'Fixed'),
                        'amount'           => $line->amount ?? ($line->component->amount_value ?? null),
                        'percentage'       => $line->percentage ?? ($line->component->percentage_value ?? null),
                        'based_on'         => $line->based_on ?: ($line->component->based_on ?? null),
                        'formula'          => $line->formula ?: ($line->component->formula ?? null),
                        'sequence'         => $line->sort_order,
                    ])->all();

                    try {
                        $bd = $engine->calculate($items)['breakdown'];
                    } catch (\Throwable $e) {
                        $this->warn("Skipped structure #{$structure->id} ({$structure->name}): {$e->getMessage()}");
                        continue;
                    }

                    $structure->update([
                        'gross_salary'          => $bd['gross_salary']['monthly'],
                        'employer_contribution' => $bd['employer_contribution']['monthly'],
                        'monthly_ctc'           => $bd['ctc']['monthly'],
                        'annual_ctc'            => $bd['ctc']['yearly'],
                        'total_deduction'       => $bd['total_deduction']['monthly'],
                        'net_salary'            => $bd['net_salary']['monthly'],
                    ]);
                    $updated++;
                }
            });

        $this->info("Backfilled totals for {$updated} salary structure(s).");

        return self::SUCCESS;
    }
}
