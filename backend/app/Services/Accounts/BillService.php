<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Models\Accounts\AccountGroup;
use App\Models\Accounts\Bill;
use App\Models\Accounts\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Vendor bills (old-CRM "Bills" parity — see modules/accounting/controllers/
 * Accounting.php `bill()` in the reference build). A bill posts a Purchase
 * voucher (Dr the chosen expense/purchase ledger, Cr the vendor's control
 * ledger under Sundry Creditors) through the one posting gate, then records
 * bill-specific metadata (due date, paid status) that isn't itself part of
 * the ledger. Paying a bill posts a second Payment voucher (Dr vendor
 * ledger, Cr bank/cash) and never edits the original — same reversal
 * discipline as the rest of the module.
 */
class BillService
{
    public function __construct(private PostingService $posting)
    {
    }

    public function list(int $tenantId, array $filters = [])
    {
        $query = Bill::forTenant($tenantId)->with('voucher:id,number', 'paidVoucher:id,number')
            ->orderByDesc('bill_date');

        if (! empty($filters['status'])) {
            if ($filters['status'] === 'overdue') {
                $query->where('status', 'unpaid')->where('due_date', '<', now()->toDateString());
            } else {
                $query->where('status', $filters['status']);
            }
        }
        if (! empty($filters['vendor'])) {
            $query->where('vendor_name', 'like', '%'.$filters['vendor'].'%');
        }

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function create(array $data, int $tenantId, int $userId): Bill
    {
        return DB::transaction(function () use ($data, $tenantId, $userId) {
            // vendor_ledger_id hook: when the Vendor module is built it will pass
            // a pre-resolved ledger ID. Until then we auto-find-or-create.
            $vendorLedger = $this->vendorLedger(
                $data['vendor_name'],
                $tenantId,
                isset($data['vendor_ledger_id']) ? (int) $data['vendor_ledger_id'] : null
            );
            $amount = round((float) $data['amount'], 2);

            $voucher = $this->posting->post([
                'voucher_type_code' => 'purchase',
                'date'              => $data['bill_date'],
                'party_id'          => $vendorLedger->id,
                'reference_no'      => $data['bill_number'] ?? null,
                'source_type'       => 'vendor_bill',
                'lines' => [
                    ['ledger_id' => $data['expense_ledger_id'], 'debit' => $amount],
                    ['ledger_id' => $vendorLedger->id, 'credit' => $amount],
                ],
            ], $tenantId, $userId);

            $bill = Bill::create([
                'tenant_id'        => $tenantId,
                'voucher_id'       => $voucher->id,
                'vendor_ledger_id' => $vendorLedger->id,
                'vendor_name'      => $data['vendor_name'],
                'bill_number'      => $data['bill_number'] ?? null,
                'bill_date'        => $data['bill_date'],
                'due_date'         => $data['due_date'],
                'amount'           => $amount,
                'status'           => 'unpaid',
                'note'             => $data['note'] ?? null,
                'attachment'       => $data['attachment'] ?? null,
                'attachment_name'  => $data['attachment_name'] ?? null,
                'is_recurring'     => $data['is_recurring'] ?? false,
                'recurring_type'   => $data['recurring_type'] ?? null,
                'recurring_every'  => $data['recurring_every'] ?? null,
                'recurring_cycles' => $data['recurring_cycles'] ?? null,
                'recurring_parent_id' => $data['recurring_parent_id'] ?? null,
                'next_recurrence_date' => ! empty($data['is_recurring'])
                    ? $this->advanceDate($data['due_date'], $data['recurring_type'], $data['recurring_every'] ?? 1)
                    : null,
                'created_by'       => $userId,
            ]);

            Log::channel('accounts')->info('Bill created', ['bill_id' => $bill->id, 'tenant_id' => $tenantId]);

            return $bill->load('voucher');
        });
    }

    /** Old-CRM "Approve payable" step — a bill must be approved before it can be paid. */
    public function approve(Bill $bill, int $tenantId, int $userId): Bill
    {
        $this->assertTenant($bill, $tenantId);

        if ($bill->approved) {
            throw new BusinessException('This bill is already approved.');
        }

        $bill->update(['approved' => true, 'approved_by' => $userId, 'approved_at' => now()]);
        Log::channel('accounts')->info('Bill approved', ['bill_id' => $bill->id, 'tenant_id' => $tenantId]);

        return $bill->fresh();
    }

    /**
     * Manually generate the next occurrence of a recurring bill (same
     * vendor/amount/expense account, dates advanced one cycle). No
     * background scheduler is wired up — a staff member triggers this
     * explicitly, same trust boundary as everything else that posts here.
     */
    public function generateNextRecurrence(Bill $bill, int $tenantId, int $userId): Bill
    {
        $this->assertTenant($bill, $tenantId);

        if (! $bill->is_recurring) {
            throw new BusinessException('This bill is not set up as recurring.');
        }
        if ($bill->recurring_cycles !== null && $bill->recurring_done >= $bill->recurring_cycles) {
            throw new BusinessException('This recurring bill has completed all its cycles.');
        }

        return DB::transaction(function () use ($bill, $tenantId, $userId) {
            $expenseLedgerId = $bill->voucher->lines()
                ->where('ledger_id', '!=', $bill->vendor_ledger_id)->value('ledger_id');

            $nextDate = $bill->next_recurrence_date ?? now()->toDateString();
            // Carbon's diffInDays is signed since Carbon 3 — force absolute so the
            // gap between bill date and due date can't invert the new bill's dates.
            $span = abs($bill->due_date->diffInDays($bill->bill_date));

            $next = $this->create([
                'vendor_name'       => $bill->vendor_name,
                'bill_number'       => $bill->bill_number,
                'bill_date'         => $nextDate,
                'due_date'          => \Illuminate\Support\Carbon::parse($nextDate)->addDays($span)->toDateString(),
                'amount'            => (float) $bill->amount,
                'expense_ledger_id' => $expenseLedgerId,
                'note'              => $bill->note,
                'is_recurring'      => true,
                'recurring_type'    => $bill->recurring_type,
                'recurring_every'   => $bill->recurring_every,
                'recurring_cycles'  => $bill->recurring_cycles,
                'recurring_parent_id' => $bill->recurring_parent_id ?? $bill->id,
            ], $tenantId, $userId);

            $bill->increment('recurring_done');

            return $next;
        });
    }

    private function advanceDate(string $from, ?string $type, int $every = 1): ?string
    {
        $d = \Illuminate\Support\Carbon::parse($from);
        return match ($type) {
            'week'  => $d->addWeeks($every)->toDateString(),
            'month' => $d->addMonths($every)->toDateString(),
            'year'  => $d->addYears($every)->toDateString(),
            default => null,
        };
    }

    public function pay(Bill $bill, array $data, int $tenantId, int $userId): Bill
    {
        $this->assertTenant($bill, $tenantId);

        if ($bill->status === 'paid') {
            throw new BusinessException('This bill is already marked paid.');
        }
        if (! $bill->approved) {
            throw new BusinessException('Approve this bill before recording payment.');
        }

        return DB::transaction(function () use ($bill, $data, $tenantId, $userId) {
            $voucher = $this->posting->post([
                'voucher_type_code' => 'payment',
                'date'              => $data['paid_date'],
                'party_id'          => $bill->vendor_ledger_id,
                'reference_no'      => $bill->bill_number,
                'source_type'       => 'vendor_bill_payment',
                'source_id'         => $bill->id,
                'lines' => [
                    ['ledger_id' => $bill->vendor_ledger_id, 'debit' => (float) $bill->amount],
                    ['ledger_id' => $data['bank_ledger_id'], 'credit' => (float) $bill->amount],
                ],
            ], $tenantId, $userId);

            $bill->update(['status' => 'paid', 'paid_voucher_id' => $voucher->id, 'paid_date' => $data['paid_date']]);

            Log::channel('accounts')->info('Bill paid', ['bill_id' => $bill->id, 'tenant_id' => $tenantId]);

            return $bill->fresh()->load('voucher', 'paidVoucher');
        });
    }

    /**
     * Resolve the vendor's control ledger under Sundry Creditors.
     *
     * Vendor module hook: when $explicitLedgerId is supplied (i.e. the caller
     * already knows the vendor's ledger, as the Vendor module will), we skip
     * the name-based find-or-create and return the existing ledger directly.
     * This is the ONLY place that needs changing when vendor management ships.
     */
    private function vendorLedger(string $vendorName, int $tenantId, ?int $explicitLedgerId = null): Ledger
    {
        // Fast path — vendor module supplies a pre-resolved ledger ID.
        if ($explicitLedgerId !== null) {
            $ledger = Ledger::forTenant($tenantId)->find($explicitLedgerId);
            if (! $ledger) {
                throw new BusinessException('Vendor ledger not found for this company.');
            }
            return $ledger;
        }

        // Fallback — free-text vendor name: find an existing party ledger by name
        // or auto-create one under Sundry Creditors (same as Tally's behaviour).
        $vendorName = trim($vendorName);
        $ledger = Ledger::forTenant($tenantId)->where('is_party', true)
            ->where('name', $vendorName)->first();
        if ($ledger) {
            return $ledger;
        }

        $group = AccountGroup::forTenant($tenantId)->where('name', 'Sundry Creditors')->first();
        if (! $group) {
            throw new BusinessException('The "Sundry Creditors" group is missing. Run accounts setup.');
        }

        return Ledger::create([
            'tenant_id' => $tenantId, 'group_id' => $group->id, 'name' => $vendorName,
            'is_party' => true, 'party_type' => 'vendor', 'opening_balance_type' => 'cr',
        ]);
    }

    private function assertTenant(Bill $bill, int $tenantId): void
    {
        if ($bill->tenant_id !== $tenantId) {
            throw new \App\Exceptions\UnauthorizedTenantException();
        }
    }

    /**
     * Delete an unpaid bill and cancel its voucher.
     *
     * Old-CRM parity: delete_bill_ajax(). Only unpaid bills may be removed;
     * paid bills are permanent because they have a matching payment voucher
     * in the ledger that cannot be silently unwound.
     */
    public function delete(Bill $bill, int $tenantId): array
    {
        $this->assertTenant($bill, $tenantId);

        if ($bill->status === 'paid') {
            throw new BusinessException('Paid bills cannot be deleted. Cancel the payment voucher first.');
        }

        return DB::transaction(function () use ($bill) {
            // Cancel the purchase voucher so the ledger stays balanced.
            if ($bill->voucher_id) {
                $bill->voucher?->update(['status' => 'cancelled']);
            }

            $bill->delete();

            Log::channel('accounts')->info('Bill deleted', ['bill_id' => $bill->id, 'tenant_id' => $bill->tenant_id]);

            return ['deleted' => true];
        });
    }
}

