<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Accounts\AccountGroup;
use App\Models\Accounts\BankAccount;
use App\Models\Accounts\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bank accounts are always backed by an is_bank ledger under the "Bank Accounts"
 * group (or "Cash-in-Hand" for a cash book). Creating a bank account creates both
 * the ledger and the bank-account row in one transaction. The cached
 * current_balance is recomputed from posted ledger lines on read.
 */
class BankAccountService
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function list(int $tenantId): array
    {
        $banks = BankAccount::forTenant($tenantId)
            ->with('ledger:id,name,group_id,opening_balance,opening_balance_type')
            ->orderBy('bank_name')
            ->get();

        // Posted movement for all bank ledgers in ONE grouped query (no per-bank query).
        $ledgerIds = $banks->pluck('ledger_id')->all();
        $movement = empty($ledgerIds) ? collect() : DB::table('acc_voucher_lines as vl')
            ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
            ->where('vl.tenant_id', $tenantId)->whereIn('vl.ledger_id', $ledgerIds)
            ->where('v.status', 'posted')->whereNull('v.deleted_at')
            ->groupBy('vl.ledger_id')
            ->selectRaw('vl.ledger_id, COALESCE(SUM(vl.debit),0) - COALESCE(SUM(vl.credit),0) as net')
            ->get()->pluck('net', 'ledger_id');

        return $banks->map(function ($b) use ($movement) {
            $opening = $b->ledger
                ? (float) $b->ledger->opening_balance * ($b->ledger->opening_balance_type === 'dr' ? 1 : -1)
                : 0.0;
            return [
                ...$b->toArray(),
                'current_balance' => round($opening + (float) ($movement[$b->ledger_id] ?? 0), 2),
            ];
        })->all();
    }

    public function create(array $data, int $tenantId, ?int $userId): BankAccount
    {
        return DB::transaction(function () use ($data, $tenantId, $userId) {
            $group = AccountGroup::forTenant($tenantId)->where('name', 'Bank Accounts')->first();
            if (! $group) {
                throw new BusinessException('The "Bank Accounts" group is missing. Run accounts setup first.');
            }

            $ledger = Ledger::create([
                'tenant_id'            => $tenantId,
                'group_id'             => $group->id,
                'name'                 => $data['name'],
                'opening_balance'      => $data['opening_balance'] ?? 0,
                'opening_balance_type' => $data['opening_balance_type'] ?? 'dr',
                'is_bank'              => true,
            ]);

            $bank = BankAccount::create([
                'tenant_id'       => $tenantId,
                'ledger_id'       => $ledger->id,
                'account_no'      => $data['account_no'] ?? null,
                'ifsc'            => $data['ifsc'] ?? null,
                'bank_name'       => $data['bank_name'] ?? $data['name'],
                'branch'          => $data['branch'] ?? null,
                'account_type'    => $data['account_type'] ?? 'current',
                'current_balance' => $data['opening_balance'] ?? 0,
            ]);

            $this->audit->log($tenantId, $userId, 'bank_account', $bank->id, 'create', after: $bank->toArray());
            Log::channel('accounts')->info('Bank account created', ['bank_account_id' => $bank->id, 'tenant_id' => $tenantId]);

            return $bank->load('ledger:id,name');
        });
    }

    public function update(BankAccount $bank, array $data, int $tenantId, ?int $userId): BankAccount
    {
        $this->assertTenant($bank, $tenantId);

        $before = $bank->toArray();
        $bank->update(array_intersect_key($data, array_flip([
            'account_no', 'ifsc', 'bank_name', 'branch', 'account_type', 'is_active',
        ])));

        if (isset($data['name'])) {
            $bank->ledger()->update(['name' => $data['name']]);
        }

        $this->audit->log($tenantId, $userId, 'bank_account', $bank->id, 'update', $before, $bank->fresh()->toArray());

        return $bank->load('ledger:id,name');
    }

    public function delete(BankAccount $bank, int $tenantId, ?int $userId): string
    {
        $this->assertTenant($bank, $tenantId);

        // If the backing ledger has postings, deactivate rather than delete.
        if ($bank->ledger && $bank->ledger->lines()->exists()) {
            $bank->update(['is_active' => false]);
            $bank->ledger()->update(['is_active' => false]);
            $this->audit->log($tenantId, $userId, 'bank_account', $bank->id, 'update', null, ['is_active' => false]);
            return 'deactivated';
        }

        DB::transaction(function () use ($bank) {
            $ledger = $bank->ledger;
            $bank->delete();
            $ledger?->delete();
        });
        $this->audit->log($tenantId, $userId, 'bank_account', $bank->id, 'update', null, null);
        return 'deleted';
    }

    /** Live balance of the backing ledger (opening + posted movement), debit-positive. */
    public function ledgerBalance(int $tenantId, int $ledgerId): float
    {
        $ledger = Ledger::forTenant($tenantId)->find($ledgerId);
        if (! $ledger) {
            return 0.0;
        }

        $opening = (float) $ledger->opening_balance * ($ledger->opening_balance_type === 'dr' ? 1 : -1);

        $movement = (float) DB::table('acc_voucher_lines as vl')
            ->join('acc_vouchers as v', 'v.id', '=', 'vl.voucher_id')
            ->where('vl.tenant_id', $tenantId)
            ->where('vl.ledger_id', $ledgerId)
            ->where('v.status', 'posted')
            ->whereNull('v.deleted_at')
            ->selectRaw('COALESCE(SUM(vl.debit),0) - COALESCE(SUM(vl.credit),0) as net')
            ->value('net');

        return round($opening + $movement, 2);
    }

    private function assertTenant(BankAccount $bank, int $tenantId): void
    {
        if ($bank->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
