<?php

namespace App\Console\Commands\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeavePolicyType;
use App\Models\Hr\HrLeaveType;
use App\Models\Tenant;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use App\Support\Hr\LeaveEntitlement;
use Illuminate\Console\Command;

/**
 * Giving employees the leave they are entitled to.
 *
 * Leave types existed and nothing turned them into per-employee balances — so
 * `My Leave` showed no balances at all and its apply form had exactly one
 * option, "No leave allocated to you yet". Nobody could book leave, in the CRM
 * or the app.
 *
 * Each type's own yearly_limit is the figure, and the only figure. See
 * daysFor() for what used to sit in front of it and why it had to go.
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

    protected $description = 'Create leave balances for employees who have none, from each leave type\'s yearly limit';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        // The leave year being opened. Calendar, January to December — one
        // value, used both to decide proration and as the balance's
        // effective_from, so the two can never describe different years.
        $yearStart = now()->startOfYear();

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

            // Read once per tenant, not once per employee.
            $prorate = (bool) app(SettingsService::class)
                ->get($tenant->id, HrSetting::GROUP, 'leave_prorate_first_year');

            // NOBODY WHO HAS LEFT. The filter was missing entirely, so an
            // exited employee picked up a fresh annual entitlement every time
            // this ran — and ExitSettlementService sums available_balance when
            // it works out what to encash, so those days had a price.
            //
            // 'Inactive' is the exit state in the employees enum
            // (Active | On Leave | Inactive). 'On Leave' is deliberately NOT
            // excluded: somebody on maternity or long sick leave is still
            // employed and still earns their year's entitlement.
            //
            // Only NEW balances are affected. An inactive employee's existing
            // rows are never touched — see the exists() check below.
            $employees = HrEmployee::where('tenant_id', $tenant->id)
                ->where('status', '!=', 'Inactive')
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

                    // The leave year is the calendar year the balance is being
                    // opened for, which is the same date written to
                    // effective_from below.
                    $days = $this->daysFor($type, $employee, $prorate, $yearStart);

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
                        'effective_from'    => $yearStart->toDateString(),
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
     * The yearly figure for a type: what the type itself says it is worth.
     *
     * THE LEAVE TYPE IS THE ONLY SOURCE. It used to be one of three. Four
     * named allowances in HR Settings — leave_casual_days, leave_paid_days,
     * leave_unpaid_days, leave_comp_off_days — were consulted first, and the
     * `?? $type->yearly_limit` beside each of them looked like a fallback but
     * could never fire: SettingsService::getGroup() starts from the registry
     * defaults, so every one of those keys is always present with a value.
     *
     * So yearly_limit was not merely overridden, it was unreachable for those
     * categories, and the Yearly Limit box on the Leave Types screen did
     * nothing for Casual, Earned or Unpaid while working normally for Sick.
     * Out of the box that shipped Earned Leave at the setting's 12 rather than
     * the seeded 15, with nothing to indicate it.
     *
     * The comp-off branch matched on the display NAME containing "comp", which
     * quietly allocated zero days to a type called Compassionate Leave. It is
     * gone with the rest: there is no Comp-Off category in
     * HrLeaveType::CATEGORIES to classify by, and inventing one would be a new
     * vocabulary rather than a fix. With every type reading its own
     * yearly_limit, no classification is needed and nothing can be
     * misclassified.
     */
    private function daysFor(HrLeaveType $type, HrEmployee $employee, bool $prorate, $yearStart): float
    {
        // yearly_limit is still the source of truth for the TYPE. What
        // LeaveEntitlement decides is how much of it this employee earns in
        // this particular year; with proration off it hands the figure back
        // unchanged, which is exactly what this method used to return.
        return LeaveEntitlement::forAllocation(
            (float) $type->yearly_limit,
            $employee->joining_date?->toDateString() ?? $employee->joining_date,
            $prorate,
            $yearStart
        );
    }

    /**
     * One shared default policy per tenant, created once — and actually
     * mapped to the leave types it is supposed to govern.
     *
     * The policy was created and stamped onto every balance while
     * hr_leave_policy_types stayed empty, so "Standard" governed nothing:
     * EmployeeLeaveBalanceService::assignPolicy() refuses a policy with no
     * mapped types ("This policy has no mapped leave types to allocate"), so
     * the one policy every balance pointed at was the one policy that could
     * never be assigned.
     *
     * IDEMPOTENT, AND IT NEVER OVERWRITES. A mapping that already exists is
     * left exactly as it is — a workspace that tuned Standard's allocation or
     * carry-forward by hand keeps its numbers, and re-running the command adds
     * only what is missing. That is the difference between backfilling a gap
     * and resetting somebody's configuration.
     */
    private function policyFor(int $tenantId): HrLeavePolicy
    {
        $policy = HrLeavePolicy::firstOrCreate(
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

        $this->mapTypesTo($policy, $tenantId);

        return $policy;
    }

    /**
     * Give the policy a row for every active leave type it does not have yet.
     *
     * The figures come from the type itself — its yearly_limit, and its own
     * carry-forward ceiling — so the default policy says the same thing the
     * Leave Types screen does rather than inventing a second set of numbers.
     * A type whose carry_forward flag is off maps with a limit of zero, which
     * is what "—" on that screen has always meant.
     */
    private function mapTypesTo(HrLeavePolicy $policy, int $tenantId): void
    {
        $mapped = HrLeavePolicyType::where('policy_id', $policy->id)
            ->pluck('leave_type_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $types = HrLeaveType::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        foreach ($types as $type) {
            if (in_array((int) $type->id, $mapped, true)) {
                // Already configured — possibly by hand. Left alone.
                continue;
            }

            HrLeavePolicyType::create([
                'policy_id'           => $policy->id,
                'leave_type_id'       => $type->id,
                'yearly_allocation'   => (float) $type->yearly_limit,
                'carry_forward_limit' => $type->carry_forward ? (float) $type->max_carry_forward : 0.0,
            ]);
        }
    }
}
