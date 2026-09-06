<?php

namespace App\Console\Commands\Hr;

use App\Models\Hr\HrStatutoryRule;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * The statutory rules a Maharashtra payroll actually runs on.
 *
 * The engine — PF, ESIC, PT, LWF, gratuity, bonus — has existed for a while and
 * is entirely config-driven, which is right: rates change by government
 * notification, not by deploy. But `hr_statutory_rules` was EMPTY, so every
 * calculator answered "not configured" and payroll computed nothing at all.
 *
 * Every figure here comes from the company's own filed documents (EPFO.docx,
 * ESIC.docx, PT & LWF.docx) and is checked against a month already filed with
 * the government — July 2026. StatutoryRegisterTest reproduces that month line
 * by line; if a rate here is wrong, that test fails with the real figure beside
 * the computed one.
 *
 * REPORT ONLY unless --commit. Seeding a wrong rate is a wrong salary for
 * everybody, so the values are printed for a human to read first.
 *
 * Existing rules are never overwritten. A tenant that has already configured PF
 * has made a decision, and an upgrade is not the place to reverse it.
 */
class SeedStatutoryRules extends Command
{
    protected $signature = 'hr:seed-statutory-rules
        {--tenant= : Restrict to one tenant id}
        {--from=2026-04-01 : effective_from for the seeded rules}
        {--commit : Actually write. Without this nothing is changed}';

    protected $description = 'Seed PF, ESIC, PT (Maharashtra) and LWF rules from the statutory documents';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $from   = (string) $this->option('from');

        $tenants = $this->option('tenant')
            ? Tenant::where('id', $this->option('tenant'))->pluck('id')
            : Tenant::pluck('id');

        foreach ($tenants as $tenantId) {
            $this->line("── tenant {$tenantId}");

            foreach ($this->rules() as [$type, $state, $config, $note]) {
                $exists = HrStatutoryRule::where('tenant_id', $tenantId)
                    ->where('rule_type', $type)
                    ->where('state', $state)
                    ->where('is_active', true)
                    ->exists();

                if ($exists) {
                    $this->line("   {$type}".($state ? " ({$state})" : '').': already configured — left alone');

                    continue;
                }

                $this->line("   {$type}".($state ? " ({$state})" : '').': '.json_encode($config));

                if ($commit) {
                    HrStatutoryRule::create([
                        'tenant_id'      => $tenantId,
                        'rule_type'      => $type,
                        'state'          => $state,
                        'effective_from' => $from,
                        'config'         => $config,
                        'is_active'      => true,
                        'notes'          => $note,
                    ]);
                }
            }
        }

        $this->newLine();
        $this->line($commit
            ? 'Written. Run the statutory register tests to check them against a filed month.'
            : 'Nothing was written. Re-run with --commit to apply.');

        return self::SUCCESS;
    }

    /** @return array<array{0:string,1:?string,2:array,3:string}> */
    private function rules(): array
    {
        return [
            ['pf', null, [
                // 12% each side. The employer's 12% is SPLIT — 8.33% to the
                // pension scheme and the remainder to EPF — rather than being an
                // extra contribution on top.
                'employee_rate'       => 12,
                'employer_rate'       => 12,
                'eps_rate'            => 8.33,
                'wage_ceiling'        => 15000,
                'restrict_to_ceiling' => true,
                // EPS membership ends at 58; after that the whole employer share
                // goes to EPF. One employee in the July register is past it.
                'eps_max_age'         => 58,
            ], 'EPFO: 12% employee, 12% employer (8.33% EPS capped at 15,000 wages = 1,250), EPS ends at 58'],

            ['esic', null, [
                'gross_threshold'   => 42000,
                'employee_rate'     => 0.75,
                'employer_rate'     => 3.25,
                // Matches the filed register: employee rounds up, employer to
                // nearest. Verified against all 8 rows of July 2026.
                'round_employee_up' => true,
            ], 'ESIC: ceiling 42,000 (25,000 for disability); 0.75% employee, 3.25% employer'],

            ['pt', 'Maharashtra', [
                'slabs' => [
                    ['from' => 0,     'to' => 7500,  'amount' => 0,   'gender' => 'M'],
                    ['from' => 7501,  'to' => 10000, 'amount' => 175, 'gender' => 'M'],
                    ['from' => 10001, 'to' => null,  'amount' => 200, 'gender' => 'M'],
                    // A woman pays nothing until 25,000 — the reason slabs carry
                    // a gender at all.
                    ['from' => 0,     'to' => 25000, 'amount' => 0,   'gender' => 'F'],
                    ['from' => 25001, 'to' => null,  'amount' => 200, 'gender' => 'F'],
                ],
                // February is 300 for everybody who pays 200 the rest of the year.
                'month_overrides' => ['2' => 300],
            ], 'Maharashtra PT: M nil/175/200 at 7,500 and 10,000; F nil/200 at 25,000; February 300'],

            ['lwf', 'Maharashtra', [
                'employee_amount' => 25,
                'employer_amount' => 75,
                // Half-yearly, on the roll at 30 June and 31 December.
                'months'          => [6, 12],
            ], 'Maharashtra LWF: 25 employee + 75 employer, half-yearly (June and December)'],
        ];
    }
}
