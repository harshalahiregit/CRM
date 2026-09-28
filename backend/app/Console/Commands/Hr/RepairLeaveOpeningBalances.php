<?php

namespace App\Console\Commands\Hr;

use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeaveBalanceTransaction;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Undo the leave balances that counted their entitlement twice.
 *
 * hr:allocate-leave used to write the year's entitlement into BOTH `allocated`
 * and `opening_balance`, then write `available_balance` explicitly — which hid
 * the inconsistency until something recomputed. recomputeAvailable() sums
 * opening + allocated + adjusted + carried_forward − used, so the first
 * approved day of leave turned a 12-day balance into 23. F&F encashes
 * available_balance at per-day basic, so those invented days had a price.
 *
 * The allocation command is fixed. This repairs what it already wrote.
 *
 * WHICH ROWS, AND HOW WE KNOW. Exactly three code paths create a leave
 * balance, and two of them always append an `Allocation` ledger transaction —
 * assignPolicy() and the manual allocate(). The command appended nothing. So a
 * balance carrying an `Allocation` transaction did NOT come from the command
 * and is not touched, whatever its numbers look like.
 *
 * That is the load-bearing test. Two weaker signals are required as well —
 * opening_balance greater than zero, and exactly equal to allocated — because
 * a genuine opening balance brought in from another payroll system is a real
 * thing this must never flatten, and requiring all three means a row has to
 * look like the bug in every respect before it is touched.
 *
 * NEGATIVE BALANCES ARE REPORTED, NEVER WRITTEN. Somebody who saw an inflated
 * 23 days and took 15 of them repairs to minus three. The over-consumption is
 * real and this did not cause it — the bug did — but writing a negative
 * balance would push a problem nobody has decided about into payroll and F&F.
 * Those rows are listed with the employee, the leave type and the arithmetic,
 * and left exactly as they are.
 *
 * ALREADY-SETTLED EXITS ARE NOT TOUCHED EITHER. ExitSettlementService freezes
 * an immutable snapshot, so repairing a balance cannot alter a settlement that
 * has already been generated. Where one was computed from an inflated balance
 * the money has been paid, and unwinding that is a finance decision, not a
 * migration.
 *
 * A COMMAND RATHER THAN A MIGRATION, deliberately. A migration runs unattended
 * on deploy, cannot offer a dry run, cannot decline a row and ask a human, and
 * cannot be pointed at one workspace. All four matter here.
 */
class RepairLeaveOpeningBalances extends Command
{
    protected $signature = 'hr:repair-leave-opening-balances
        {--tenant= : Restrict to one tenant id}
        {--commit : Actually write. Without this nothing is changed}';

    protected $description = 'Clear the duplicated opening_balance on leave balances created by hr:allocate-leave';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->whereKey((int) $t))
            ->orderBy('id')->get(['id', 'name']);

        $repairable = [];
        $skipped    = [];
        $alreadyOk  = 0;

        foreach ($tenants as $tenant) {
            foreach ($this->candidates((int) $tenant->id) as $balance) {
                $corrected = $this->correctedAvailable($balance);

                // Untouched rows are already showing the right number — their
                // available_balance was written explicitly and nothing has
                // recomputed it yet. They are still repaired, because the next
                // adjustment or day of leave would break them, but they are
                // counted separately so the report does not overstate the
                // damage.
                $visiblyWrong = round((float) $balance->available_balance, 1) !== $corrected;

                if ($corrected < 0) {
                    $skipped[] = $this->describe($tenant, $balance, $corrected);

                    continue;
                }

                $repairable[] = [$tenant, $balance, $corrected];

                if (! $visiblyWrong) {
                    $alreadyOk++;
                }
            }
        }

        if ($commit) {
            $this->applyAll($repairable);
        }

        $this->report($repairable, $skipped, $alreadyOk, $commit);

        return self::SUCCESS;
    }

    /**
     * Rows that look like the bug in every respect.
     *
     * The three conditions are deliberately redundant. Any one of them alone
     * would be an inference; together they describe a row that only
     * hr:allocate-leave could have written.
     */
    private function candidates(int $tenantId)
    {
        return HrEmployeeLeaveBalance::where('tenant_id', $tenantId)
            ->where('opening_balance', '>', 0)
            ->whereColumn('opening_balance', 'allocated')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('hr_leave_balance_transactions as t')
                    ->whereColumn('t.employee_leave_balance_id', 'hr_employee_leave_balances.id')
                    ->where('t.transaction_type', 'Allocation');
            })
            ->with(['employee:id,name,employee_code', 'leaveType:id,name'])
            ->orderBy('id')
            ->get();
    }

    /** What available_balance becomes once the duplicate opening is gone. */
    private function correctedAvailable(HrEmployeeLeaveBalance $b): float
    {
        return round(
            (float) $b->allocated + (float) $b->adjusted + (float) $b->carried_forward - (float) $b->used,
            1
        );
    }

    /**
     * Write the correction.
     *
     * Only two columns move. used, adjusted, carried_forward and every ledger
     * transaction are left exactly as they are — this is correcting a field
     * that was never an opening balance, not restating somebody's leave
     * history.
     *
     * IDEMPOTENT by construction: the repair sets opening_balance to 0, and
     * the candidate query requires it to be greater than 0, so a second run
     * finds nothing.
     */
    private function applyAll(array $repairable): void
    {
        foreach ($repairable as [$tenant, $balance, $corrected]) {
            DB::transaction(function () use ($balance, $corrected) {
                $was = (float) $balance->available_balance;

                $balance->forceFill([
                    'opening_balance'   => 0,
                    'available_balance' => $corrected,
                ])->save();

                // The ledger records that a correction happened, without
                // pretending leave was granted or taken.
                HrLeaveBalanceTransaction::create([
                    'tenant_id'                 => $balance->tenant_id,
                    'employee_leave_balance_id' => $balance->id,
                    'transaction_type'          => 'Correction',
                    'quantity'                  => round($corrected - $was, 1),
                    'remarks'                   => 'Duplicated opening_balance cleared (hr:repair-leave-opening-balances)',
                ]);
            });

            Log::channel('hr')->info('Leave opening balance repaired', [
                'tenant_id' => $balance->tenant_id, 'balance_id' => $balance->id,
                'employee_id' => $balance->employee_id, 'available' => $corrected,
            ]);
        }
    }

    /** Enough for HR or finance to find the person, without a database. */
    private function describe(Tenant $tenant, HrEmployeeLeaveBalance $b, float $corrected): array
    {
        return [
            $tenant->id,
            $b->employee?->employee_code ?? ('#'.$b->employee_id),
            $b->employee?->name ?? '—',
            $b->leaveType?->name ?? ('#'.$b->leave_type_id),
            (float) $b->allocated,
            (float) $b->used,
            (float) $b->available_balance,
            $corrected,
        ];
    }

    private function report(array $repairable, array $skipped, int $alreadyOk, bool $commit): void
    {
        if (! $commit) {
            $this->line('DRY RUN — nothing has been changed.');
            $this->newLine();
        }

        $visiblyWrong = count($repairable) - $alreadyOk;

        $this->table(['Outcome', 'Balances'], [
            [$commit ? 'Repaired (balance was wrong)' : 'Would repair (balance is wrong)', $visiblyWrong],
            [$commit ? 'Repaired (not yet visibly wrong)' : 'Would repair (not yet visibly wrong)', $alreadyOk],
            ['SKIPPED — would go negative', count($skipped)],
        ]);

        if ($skipped !== []) {
            $this->newLine();
            $this->warn('These balances were NOT changed. Each one has more leave recorded as used than');
            $this->warn('the corrected entitlement allows, because the inflated balance let somebody book');
            $this->warn('leave they were not entitled to. Repairing them would write a negative balance,');
            $this->warn('which is a decision for HR and finance rather than this command.');
            $this->newLine();

            $this->table(
                ['Tenant', 'Employee', 'Name', 'Leave type', 'Allocated', 'Used', 'Available now', 'Would become'],
                $skipped
            );
        }

        $this->newLine();

        if (! $commit) {
            $this->info('Re-run with --commit to apply. Skipped rows stay skipped either way.');

            return;
        }

        $this->info(sprintf('%d balance(s) repaired, %d skipped.', count($repairable), count($skipped)));

        if ($skipped !== []) {
            $this->warn('Settlements already generated from an inflated balance are NOT adjusted — an exit '
                .'settlement freezes its own snapshot, so any over-payment is a finance decision.');
        }
    }
}
