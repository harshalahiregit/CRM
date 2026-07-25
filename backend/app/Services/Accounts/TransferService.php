<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Models\Accounts\AccountGroup;
use App\Models\Accounts\Ledger;
use App\Models\Accounts\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * Transfer Funds — a dead-simple "move money from Account A to Account B"
 * screen over the voucher engine. The user never sees voucher types, numbering,
 * or debit/credit — they pick a From account, a To account, an amount, and a
 * Category, and this service works out the correct posting:
 *
 *   Own account  → Own account  → Contra  (internal transfer)
 *   Own account  → Client/Vendor/TPV → Payment (money going out to a party —
 *                                       e.g. returning an excess payment)
 *   Client/Vendor/TPV → Own account  → Receipt (money coming in from a party —
 *                                       e.g. a vendor refunding a double payment)
 *   Party → Party (rare)             → Journal
 *
 * Accounts are grouped for the picker into own / client / vendor / tpv, driven
 * by `acc_ledgers.party_type` (spec's client/vendor/TPV account types). Until
 * the Vendor/TPV modules exist, those groups are simply empty — nothing else
 * needs to change when they ship (their posting bridge just tags
 * `party_type` when it creates a party ledger, exactly like SalesPostingBridge
 * and BillService already do for 'client'/'vendor').
 */
class TransferService
{
    /** Ledger groups whitelisted as "My Accounts" — matches the brief's own examples (HDFC, Cash, GST, TDS). */
    private const OWN_GROUPS = ['Bank Accounts', 'Cash-in-Hand', 'Duties & Taxes', 'Loans & Advances (Asset)'];

    public function __construct(private PostingService $posting, private ReversalService $reversal)
    {
    }

    /**
     * Every account a user might transfer to/from, grouped for the picker.
     * @return array{own: array, client: array, vendor: array, tpv: array}
     */
    public function pickableAccounts(int $tenantId): array
    {
        $rows = DB::table('acc_ledgers as l')
            ->join('acc_account_groups as g', 'g.id', '=', 'l.group_id')
            ->where('l.tenant_id', $tenantId)->where('l.is_active', true)->whereNull('l.deleted_at')
            ->where(function ($q) {
                $q->where('l.is_party', true)->orWhereIn('g.name', self::OWN_GROUPS);
            })
            ->orderBy('l.name')
            ->get(['l.id', 'l.name', 'l.is_bank', 'l.is_cash', 'l.is_party', 'l.party_type', 'g.name as group_name']);

        $out = ['own' => [], 'client' => [], 'vendor' => [], 'tpv' => []];
        foreach ($rows as $r) {
            $bucket = $this->classify($r);
            $out[$bucket][] = [
                'id' => $r->id, 'name' => $r->name, 'group' => $r->group_name,
                'is_bank' => (bool) $r->is_bank, 'is_cash' => (bool) $r->is_cash,
            ];
        }

        return $out;
    }

    /**
     * @param array $data {from_ledger_id, to_ledger_id, date, amount, narration?, notes_html?, transfer_category_id?}
     */
    public function transfer(array $data, int $tenantId, ?int $userId): Voucher
    {
        if ((int) $data['from_ledger_id'] === (int) $data['to_ledger_id']) {
            throw new BusinessException('From and To accounts must be different.');
        }

        $from = $this->ledger((int) $data['from_ledger_id'], $tenantId);
        $to = $this->ledger((int) $data['to_ledger_id'], $tenantId);
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new BusinessException('Amount must be greater than zero.');
        }

        $fromIsOwn = $this->classify((object) ['is_party' => $from->is_party, 'party_type' => $from->party_type, 'group_name' => $from->group->name ?? null]) === 'own';
        $toIsOwn = $this->classify((object) ['is_party' => $to->is_party, 'party_type' => $to->party_type, 'group_name' => $to->group->name ?? null]) === 'own';

        $voucherType = match (true) {
            $fromIsOwn && $toIsOwn => 'contra',
            $fromIsOwn && ! $toIsOwn => 'payment',   // money OUT to a client/vendor/TPV
            ! $fromIsOwn && $toIsOwn => 'receipt',   // money IN from a client/vendor/TPV
            default => 'journal',                     // party → party (rare)
        };

        // Attach the party for statements: whichever leg is a party account.
        $partyId = ! $toIsOwn ? $to->id : (! $fromIsOwn ? $from->id : null);

        return $this->posting->post([
            'voucher_type_code' => $voucherType,
            'date'              => $data['date'],
            'narration'         => $data['narration'] ?: 'Fund transfer',
            'notes_html'        => $data['notes_html'] ?? null,
            'party_id'          => $partyId,
            'source_type'       => 'fund_transfer',
            'transfer_category_id' => $data['transfer_category_id'] ?? null,
            'lines' => [
                ['ledger_id' => $to->id, 'debit' => $amount, 'line_narration' => $data['narration'] ?? null],
                ['ledger_id' => $from->id, 'credit' => $amount, 'line_narration' => $data['narration'] ?? null],
            ],
        ], $tenantId, $userId);
    }

    public function history(int $tenantId, array $filters)
    {
        $query = Voucher::forTenant($tenantId)
            ->where('source_type', 'fund_transfer')
            ->with(['lines.ledger:id,name', 'transferCategory:id,name', 'reversedVoucher:id,number'])
            ->withCount('reversedBy');

        if (! empty($filters['category_id'])) {
            $query->where('transfer_category_id', $filters['category_id']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('date', '<=', $filters['to']);
        }

        return $query->orderByDesc('date')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function cancel(Voucher $voucher, int $tenantId, ?int $userId, ?string $reason): Voucher
    {
        if ($voucher->tenant_id !== $tenantId || $voucher->source_type !== 'fund_transfer') {
            throw new BusinessException('Transfer not found.');
        }
        return $this->reversal->cancel($voucher, $tenantId, $userId, $reason ?: 'Transfer reversed');
    }

    /** own | client | vendor | tpv, from a row carrying is_party/party_type/group_name. */
    private function classify(object $row): string
    {
        if (! $row->is_party) {
            return 'own';
        }
        return in_array($row->party_type, ['client', 'vendor', 'tpv'], true) ? $row->party_type
            : ($row->group_name === 'Sundry Creditors' ? 'vendor' : 'client'); // legacy party ledgers, no party_type yet
    }

    private function ledger(int $id, int $tenantId): Ledger
    {
        $ledger = Ledger::forTenant($tenantId)->with('group:id,name')->find($id);
        if (! $ledger) {
            throw new BusinessException('Account not found.');
        }
        return $ledger;
    }
}
