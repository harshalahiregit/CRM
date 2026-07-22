<?php

namespace App\Services\Accounts;

use App\Events\Accounts\VoucherReversed;
use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Accounts\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cancels a posted voucher WITHOUT deleting it (spec §7.2). A mirror voucher
 * (debits↔credits swapped) is posted through PostingService to neutralise the
 * original's ledger effect, and the original is flagged `cancelled`. Both remain
 * in the books, preserving the audit trail.
 */
class ReversalService
{
    public function __construct(
        private PostingService $posting,
        private AuditLogger $audit,
    ) {
    }

    public function cancel(Voucher $voucher, int $tenantId, ?int $userId, ?string $reason = null): Voucher
    {
        if ($voucher->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
        if ($voucher->status !== 'posted') {
            throw new BusinessException('Only a posted voucher can be cancelled.');
        }
        if ($voucher->is_reversal) {
            throw new BusinessException('A reversing voucher cannot itself be cancelled.');
        }
        if ($voucher->reversedBy()->exists()) {
            throw new BusinessException('This voucher has already been reversed.');
        }

        return DB::transaction(function () use ($voucher, $tenantId, $userId, $reason) {
            $voucher->loadMissing('lines', 'voucherType');

            // Post the neutralising entry (dated today, into the open period).
            $reversalLines = $voucher->lines->map(fn ($line) => [
                'ledger_id'      => $line->ledger_id,
                'debit'          => (float) $line->credit,   // swap
                'credit'         => (float) $line->debit,    // swap
                'line_narration' => $line->line_narration,
            ])->all();

            $reversal = $this->posting->post([
                'voucher_type_code'   => $voucher->voucherType->code,
                'date'                => now()->toDateString(),
                'narration'           => 'Reversal of ' . $voucher->number . ($reason ? " — {$reason}" : ''),
                'party_id'            => $voucher->party_id,
                'reference_no'        => $voucher->number,
                'lines'               => $reversalLines,
                'is_reversal'         => true,
                'reversed_voucher_id' => $voucher->id,
            ], $tenantId, $userId);

            // The original stays POSTED and untouched in its own period; the
            // reversing entry (also posted, dated today) neutralises it so every
            // report nets to zero without retroactively altering a prior period.
            $this->audit->log(
                $tenantId, $userId, 'voucher', $voucher->id, 'reverse',
                before: $voucher->toArray(),
                after: $reversal->toArray(),
            );

            Log::channel('accounts')->info('Voucher cancelled via reversal', [
                'voucher_id' => $voucher->id, 'reversal_id' => $reversal->id, 'tenant_id' => $tenantId,
            ]);

            VoucherReversed::dispatch($voucher, $reversal);

            return $reversal;
        });
    }
}
