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
            $vendorLedger = $this->vendorLedger($data['vendor_name'], $tenantId);
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
                'created_by'       => $userId,
            ]);

            Log::channel('accounts')->info('Bill created', ['bill_id' => $bill->id, 'tenant_id' => $tenantId]);

            return $bill->load('voucher');
        });
    }

    public function pay(Bill $bill, array $data, int $tenantId, int $userId): Bill
    {
        $this->assertTenant($bill, $tenantId);

        if ($bill->status === 'paid') {
            throw new BusinessException('This bill is already marked paid.');
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

    /** Find (or create) this vendor's control ledger under Sundry Creditors. */
    private function vendorLedger(string $vendorName, int $tenantId): Ledger
    {
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
            'is_party' => true, 'opening_balance_type' => 'cr',
        ]);
    }

    private function assertTenant(Bill $bill, int $tenantId): void
    {
        if ($bill->tenant_id !== $tenantId) {
            throw new \App\Exceptions\UnauthorizedTenantException();
        }
    }
}
