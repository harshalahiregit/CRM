<?php

namespace App\Services\Accounts;

use App\Events\Accounts\VoucherPosted;
use App\Exceptions\BusinessException;
use App\Models\Accounts\FinancialYear;
use App\Models\Accounts\Ledger;
use App\Models\Accounts\Voucher;
use App\Models\Accounts\VoucherTaxLine;
use App\Models\Accounts\VoucherType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONE gate through which every posting to the ledger passes (spec v2 §2).
 * No other code writes acc_voucher_lines directly.
 *
 * Guarantees per post, all inside a single DB::transaction:
 *   1. the voucher balances to zero in integer paise (the invariant, spec §7.1);
 *   2. a gap-less number is allocated atomically (NumberingService);
 *   3. the target financial year exists and is not locked (spec §7.3);
 *   4. every mutation is written to the append-only audit trail (spec §7.6);
 *   5. VoucherPosted fires for downstream (v2) listeners.
 */
class PostingService
{
    public function __construct(
        private NumberingService $numbering,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @param array $data {
     *   voucher_type_code: string, date: string, narration?: string,
     *   party_id?: int, reference_no?: string,
     *   lines: array<array{ledger_id:int, debit?:float, credit?:float, line_narration?:string}>
     * }
     */
    public function post(array $data, int $tenantId, ?int $userId): Voucher
    {
        return DB::transaction(function () use ($data, $tenantId, $userId) {
            $type = VoucherType::forTenant($tenantId)
                ->where('code', $data['voucher_type_code'])
                ->first();

            if (! $type) {
                throw new BusinessException("Unknown voucher type '{$data['voucher_type_code']}'. Run accounts setup first.");
            }

            $fy = $this->resolveFinancialYear($tenantId, $data['date']);

            $lines = $this->prepareLines($data['lines'] ?? [], $tenantId);

            // ── The invariant: Σ debits − Σ credits = 0, in exact paise ──
            [$debitPaise, $creditPaise] = $this->totals($lines);
            if ($debitPaise !== $creditPaise) {
                throw new BusinessException(sprintf(
                    'Voucher does not balance: debits ₹%s vs credits ₹%s.',
                    number_format($debitPaise / 100, 2),
                    number_format($creditPaise / 100, 2),
                ));
            }
            if ($debitPaise === 0) {
                throw new BusinessException('Voucher total cannot be zero.');
            }

            $number = $this->numbering->allocate($tenantId, $type, $fy);

            $voucher = Voucher::create([
                'tenant_id'         => $tenantId,
                'voucher_type_id'   => $type->id,
                'financial_year_id' => $fy->id,
                'number'            => $number,
                'date'              => $data['date'],
                'narration'         => $data['narration'] ?? null,
                'party_id'          => $data['party_id'] ?? null,
                'reference_no'      => $data['reference_no'] ?? null,
                'status'            => 'posted',
                'total_amount'      => $debitPaise / 100,
                'is_reversal'       => $data['is_reversal'] ?? false,
                'reversed_voucher_id' => $data['reversed_voucher_id'] ?? null,
                'source_type'       => $data['source_type'] ?? null,
                'source_id'         => $data['source_id'] ?? null,
                'transfer_category_id' => $data['transfer_category_id'] ?? null,
                'created_by'        => $userId,
            ]);

            foreach ($lines as $line) {
                $voucher->lines()->create([
                    'tenant_id'      => $tenantId,
                    'ledger_id'      => $line['ledger_id'],
                    'debit'          => $line['debit'],
                    'credit'         => $line['credit'],
                    'line_narration' => $line['line_narration'] ?? null,
                ]);
            }

            // Optional tax breakup metadata (drives GSTR-1/3B and TDS returns).
            foreach ($data['tax_lines'] ?? [] as $tl) {
                VoucherTaxLine::create([
                    'tenant_id'             => $tenantId,
                    'voucher_id'            => $voucher->id,
                    'tax_type'              => $tl['tax_type'],
                    'rate'                  => $tl['rate'] ?? 0,
                    'taxable_amount'        => $tl['taxable_amount'] ?? 0,
                    'tax_amount'            => $tl['tax_amount'] ?? 0,
                    'hsn_sac_id'            => $tl['hsn_sac_id'] ?? null,
                    'tds_section_code'      => $tl['tds_section_code'] ?? null,
                    'place_of_supply_state' => $tl['place_of_supply_state'] ?? null,
                    'direction'             => $tl['direction'] ?? 'outward',
                ]);
            }

            $this->audit->log(
                $tenantId, $userId, 'voucher', $voucher->id, 'create',
                before: null,
                after: $voucher->load('lines')->toArray(),
            );

            Log::channel('accounts')->info('Voucher posted', [
                'voucher_id' => $voucher->id, 'tenant_id' => $tenantId,
                'type' => $type->code, 'number' => $number, 'total' => $voucher->total_amount,
            ]);

            VoucherPosted::dispatch($voucher);

            return $voucher->load('lines.ledger', 'voucherType');
        });
    }

    /** Find the open FY containing $date, or fail with a clear message. */
    public function resolveFinancialYear(int $tenantId, string $date): FinancialYear
    {
        $fy = FinancialYear::forTenant($tenantId)->containing($date)->first();

        if (! $fy) {
            throw new BusinessException('No financial year covers this date. Create one in Settings first.');
        }
        if ($fy->is_closed) {
            throw new BusinessException('This financial year is closed. Post corrections to the open period.');
        }

        return $fy;
    }

    /**
     * Normalise + validate lines: each leg is exclusively a debit OR a credit,
     * amounts are non-negative, and every ledger belongs to the tenant.
     */
    private function prepareLines(array $rawLines, int $tenantId): array
    {
        if (count($rawLines) < 2) {
            throw new BusinessException('A voucher needs at least two lines (one debit and one credit).');
        }

        $validLedgerIds = Ledger::forTenant($tenantId)
            ->whereIn('id', array_column($rawLines, 'ledger_id'))
            ->pluck('id')
            ->all();

        $prepared = [];
        foreach ($rawLines as $i => $row) {
            $ledgerId = (int) ($row['ledger_id'] ?? 0);
            if (! in_array($ledgerId, $validLedgerIds, true)) {
                throw new BusinessException("Line " . ($i + 1) . ": ledger does not exist or belongs to another tenant.");
            }

            $debit  = round((float) ($row['debit'] ?? 0), 2);
            $credit = round((float) ($row['credit'] ?? 0), 2);

            if ($debit < 0 || $credit < 0) {
                throw new BusinessException("Line " . ($i + 1) . ": amounts cannot be negative.");
            }
            if ($debit > 0 && $credit > 0) {
                throw new BusinessException("Line " . ($i + 1) . ": a line is either a debit or a credit, not both.");
            }
            if ($debit == 0 && $credit == 0) {
                continue; // skip empty rows the UI may send
            }

            $prepared[] = [
                'ledger_id'      => $ledgerId,
                'debit'          => $debit,
                'credit'         => $credit,
                'line_narration' => $row['line_narration'] ?? null,
            ];
        }

        if (count($prepared) < 2) {
            throw new BusinessException('A voucher needs at least two non-empty lines.');
        }

        return $prepared;
    }

    /** Sum debits and credits in integer paise via bcmath (exact, no float drift). */
    private function totals(array $lines): array
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $line) {
            $debit  += (int) bcmul(number_format($line['debit'], 2, '.', ''), '100', 0);
            $credit += (int) bcmul(number_format($line['credit'], 2, '.', ''), '100', 0);
        }

        return [$debit, $credit];
    }
}
