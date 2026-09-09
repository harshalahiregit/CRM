<?php

namespace App\Console\Commands\Hr;

use App\Models\Hr\HrSalaryComponent;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * The earning heads a salary is built from, and what each one counts towards.
 *
 * hr_salary_components was EMPTY. The statutory engine derives its wage bases by
 * summing components flagged pf_applicable and esic_applicable — with no
 * components there is no base, so a payroll run had nothing to compute from even
 * once the rules were seeded.
 *
 * The heads are the ones on the company's own salary sheet. The FLAGS are the
 * part that matters, and they are not guesses: on the filed July register,
 *
 *     Basic + DA  =  the ESIC salary, exactly, for every employee
 *     SD104: 14259 + 4194 = 18453, and the ESIC register says 18453
 *
 * and PF wages are the same base capped at the 15,000 ceiling. So Basic and DA
 * carry both flags and nothing else does.
 *
 * WORTH KNOWING, because it is a decision rather than a fact: this company
 * assesses ESIC ELIGIBILITY on Basic + DA too, not on gross. Three employees on
 * the July ESIC register earn above the 42,000 ceiling — assessed on gross they
 * would be outside ESIC altogether and contribute nothing, so the effect is to
 * pay MORE, not less. It is reproduced here because it is what was filed, and
 * flagged because it is the kind of thing a consultant should confirm.
 *
 * REPORT ONLY unless --commit, and never touches a head that already exists.
 */
class SeedSalaryComponents extends Command
{
    protected $signature = 'hr:seed-salary-components
        {--tenant= : Restrict to one tenant id}
        {--commit : Actually write. Without this nothing is changed}';

    protected $description = 'Seed the salary earning heads with their PF / ESIC / taxable flags';

    public function handle(): int
    {
        $commit  = (bool) $this->option('commit');
        $tenants = $this->option('tenant')
            ? Tenant::where('id', $this->option('tenant'))->pluck('id')
            : Tenant::pluck('id');

        foreach ($tenants as $tenantId) {
            $this->line("── tenant {$tenantId}");

            foreach ($this->components() as $i => $c) {
                $exists = HrSalaryComponent::where('tenant_id', $tenantId)
                    ->where('code', $c['code'])->exists();

                if ($exists) {
                    $this->line("   {$c['code']}: already exists — left alone");

                    continue;
                }

                $flags = implode(' ', array_filter([
                    $c['pf_applicable'] ? 'PF' : null,
                    $c['esic_applicable'] ? 'ESIC' : null,
                    $c['taxable'] ? 'taxable' : null,
                ])) ?: 'none';

                $this->line('   '.str_pad($c['code'], 10).str_pad($c['name'], 30).$flags);

                if ($commit) {
                    HrSalaryComponent::create($c + [
                        'tenant_id' => $tenantId,
                        'sequence'  => ($i + 1) * 10,
                        'is_active' => true,
                    ]);
                }
            }
        }

        $this->newLine();
        $this->line($commit ? 'Written.' : 'Nothing was written. Re-run with --commit to apply.');

        return self::SUCCESS;
    }

    /** @return array<array<string, mixed>> */
    private function components(): array
    {
        return [
            // ── The PF and ESIC wage base ──
            ['code' => 'BASIC', 'name' => 'Basic', 'type' => 'Earning',
             'calculation_type' => 'Manual', 'pf_applicable' => true, 'esic_applicable' => true, 'taxable' => true,
             'description' => 'Part of the PF and ESIC wage base.'],

            ['code' => 'DA', 'name' => 'Dearness Allowance', 'type' => 'Earning',
             'calculation_type' => 'Manual', 'pf_applicable' => true, 'esic_applicable' => true, 'taxable' => true,
             'description' => 'Part of the PF and ESIC wage base, alongside Basic.'],

            // ── Outside both bases ──
            ['code' => 'HRA', 'name' => 'House Rent Allowance', 'type' => 'Earning',
             'calculation_type' => 'Manual', 'pf_applicable' => false, 'esic_applicable' => false, 'taxable' => true,
             'description' => 'Excluded from PF wages by statute. Taxable, less the HRA exemption.'],

            ['code' => 'CCA', 'name' => 'City Compensatory Allowance', 'type' => 'Earning',
             'calculation_type' => 'Manual', 'pf_applicable' => false, 'esic_applicable' => false, 'taxable' => true],

            ['code' => 'SPL', 'name' => 'Special Allowance', 'type' => 'Earning',
             'calculation_type' => 'Manual', 'pf_applicable' => false, 'esic_applicable' => false, 'taxable' => true,
             'description' => 'Not in the PF base as this company files it. Note that where a special allowance is paid universally to all staff, it has been held to form part of PF wages — worth a consultant view if it grows.'],

            ['code' => 'OTHER', 'name' => 'Other Earnings', 'type' => 'Earning',
             'calculation_type' => 'Manual', 'pf_applicable' => false, 'esic_applicable' => false, 'taxable' => true],

            // ── Occasional heads ──
            ['code' => 'OT', 'name' => 'Overtime', 'type' => 'Earning',
             'calculation_type' => 'Manual', 'pf_applicable' => false, 'esic_applicable' => true, 'taxable' => true,
             'description' => 'Overtime counts as ESIC wages, though it is excluded when deciding ESIC eligibility. It is not PF wages.'],

            ['code' => 'ARREARS', 'name' => 'Arrears', 'type' => 'Earning',
             'calculation_type' => 'Manual', 'pf_applicable' => true, 'esic_applicable' => true, 'taxable' => true,
             'description' => 'Arrears of wages carry the same character as the wages they correct.'],

            ['code' => 'BONUS', 'name' => 'Bonus / Incentive', 'type' => 'Earning',
             'calculation_type' => 'Manual', 'pf_applicable' => false, 'esic_applicable' => false, 'taxable' => true,
             'description' => 'Statutory bonus is outside both the PF and the ESIC wage base.'],

            ['code' => 'LVENC', 'name' => 'Leave Encashment', 'type' => 'Earning',
             'calculation_type' => 'Manual', 'pf_applicable' => false, 'esic_applicable' => false, 'taxable' => true,
             'description' => 'Outside both bases. Taxability on exit is governed separately.'],
        ];
    }
}
