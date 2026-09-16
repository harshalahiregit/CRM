<?php

namespace App\Console\Commands\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeaveType;
use App\Models\Tenant;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use Illuminate\Console\Command;

/**
 * Giving employees the leave they are entitled to.
 *
 * Leave types and the yearly-day settings both existed, and nothing turned them
 * into per-employee balances — so `My Leave` showed no balances at all and its
 * apply form had exactly one option, "No leave allocated to you yet". Nobody
 * could book leave, in the CRM or the app.
 *
 * A command rather than something automatic: how much leave somebody gets is a
 * company's decision, and quietly granting it on first login would be inventing
 * an entitlement. Dry-run by default, and it never touches an existing balance.
 */
class AllocateLeaveBalances extends Command
{
    protected $signature = 'hr:allocate-leave
        {--tenant= : Restrict to one tenant id}
        {--employee= : Restrict to one employee id}
        {--commit : Actually write. Without this nothing is changed}';

    protected $description = 'Create leave balances for employees who have none, from the leave types and the yearly-day settings';

    public function handle(SettingsService $settings): int
    {
        $commit = (bool) $this->option('commit');

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->whereKey((int) $t))
            ->orderBy('id')->get(['id', 'name']);

        $rows = [];
        $created = 0;

        foreach ($tenants as $tenant) {
            $types = HrLeaveType::where('tenant_id', $tenant->id)->where('is_active', true)->get();

            if ($types->isEmpty()) {
                $rows[] = [$tenant->id, $tenant->name, '—', 'no leave types — run hr:seed-masters first'];

                continue;
            }

            $policy = $commit ? $this->policyFor((int) $tenant->id) : null;
            $s      = $settings->getGroup((int) $tenant->id, HrSetting::GROUP);

            $employees = HrEmployee::where('tenant_id', $tenant->id)
                ->when($this->option('employee'), fn ($q, $e) => $q->whereKey((int) $e))
                ->orderBy('name')->get();

            foreach ($employees as $employee) {
                foreach ($types as $type) {
                    $exists = HrEmployeeLeaveBalance::where('tenant_id', $tenant->id)
                        ->where('employee_id', $employee->id)
                        ->where('leave_type_id', $type->id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $days = $this->daysFor($type, $s);

                    if (! $commit) {
                        $rows[] = [$tenant->id, $employee->employee_code, $type->name, "would allocate {$days}"];

                        continue;
                    }

                    HrEmployeeLeaveBalance::create([
                        'tenant_id'         => $tenant->id,
                        'employee_id'       => $employee->id,
                        'leave_policy_id'   => $policy->id,
                        'leave_type_id'     => $type->id,
                        'allocated'         => $days,
                        'opening_balance'   => $days,
                        'used'              => 0,
                        'adjusted'          => 0,
                        'carried_forward'   => 0,
                        'available_balance' => $days,
                        'effective_from'    => now()->startOfYear()->toDateString(),
                        'status'            => HrEmployeeLeaveBalance::ACTIVE,
                    ]);

                    $created++;
                    $rows[] = [$tenant->id, $employee->employee_code, $type->name, "allocated {$days}"];
                }
            }
        }

        if (! $rows) {
            $this->info('Every employee already has a balance for every leave type.');

            return self::SUCCESS;
        }

        if (! $commit) {
            $this->line('DRY RUN — nothing will be written.');
            $this->newLine();
        }

        $this->table(['Tenant', 'Employee', 'Leave type', 'Outcome'], array_slice($rows, 0, 60));

        if (count($rows) > 60) {
            $this->line('  … and '.(count($rows) - 60).' more.');
        }

        $this->newLine();
        $this->info($commit
            ? "Allocated {$created} balance(s)."
            : 'Re-run with --commit to write. Existing balances are never touched.');

        return self::SUCCESS;
    }

    /**
     * The yearly figure for a type.
     *
     * The settings carry four named allowances; a type outside those falls back
     * to its own yearly_limit, which is what the type itself says it is worth.
     */
    private function daysFor(HrLeaveType $type, array $s): float
    {
        $category = strtolower((string) $type->category);
        $name     = strtolower((string) $type->name);

        return (float) match (true) {
            str_contains($name, 'comp')  => $s['leave_comp_off_days'] ?? $type->yearly_limit,
            $category === 'casual'       => $s['leave_casual_days'] ?? $type->yearly_limit,
            $category === 'unpaid'       => $s['leave_unpaid_days'] ?? $type->yearly_limit,
            $category === 'earned'       => $s['leave_paid_days'] ?? $type->yearly_limit,
            default                      => $type->yearly_limit,
        };
    }

    /** One shared default policy per tenant, created once. */
    private function policyFor(int $tenantId): HrLeavePolicy
    {
        return HrLeavePolicy::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Standard'],
            [
                'applies_to'               => 'All',
                // Weekends and holidays inside a leave range are NOT charged —
                // the ordinary reading, and the one somebody expects when they
                // book Friday to Monday.
                'weekends_count'           => false,
                'holidays_count'           => false,
                'half_day_allowed'         => true,
                'negative_balance_allowed' => false,
                'is_active'                => true,
                'description'              => 'Created by hr:allocate-leave.',
            ]
        );
    }
}
