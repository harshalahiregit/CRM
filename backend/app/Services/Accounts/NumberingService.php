<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Models\Accounts\FinancialYear;
use App\Models\Accounts\NumberingSeries;
use App\Models\Accounts\Voucher;
use App\Models\Accounts\VoucherType;

/**
 * Gap-less, sequential voucher numbering per type per financial year — the Indian
 * statutory expectation (spec §4.5). Numbers reset each FY when reset_frequency is
 * 'yearly'.
 *
 * The counter is derived (count of vouchers already allocated for this scope + 1)
 * rather than a stored cursor, so a rolled-back post never burns a number (no
 * gaps). Correctness under concurrency comes from lockForUpdate()-ing the series
 * row INSIDE the caller's DB::transaction: two posts of the same type serialise on
 * that lock, so they can't compute the same count. Must run inside a transaction.
 */
class NumberingService
{
    /** Reserve and return the next formatted number, e.g. "SAL-2026-0001". */
    public function allocate(int $tenantId, VoucherType $type, FinancialYear $fy): string
    {
        // Serialise concurrent allocations for this voucher type (held till commit).
        $series = NumberingSeries::where('tenant_id', $tenantId)
            ->where('voucher_type_code', $type->code)
            ->lockForUpdate()
            ->first();

        if (! $series) {
            throw new BusinessException("No numbering series configured for voucher type '{$type->code}'. Run accounts setup first.");
        }

        $yearly = $series->reset_frequency === 'yearly';

        // Count vouchers already allocated in this scope (this FY when yearly,
        // else all-time for the type). withTrashed so a cancelled/soft-deleted
        // voucher still consumes its number — numbers are never reused.
        $used = Voucher::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('voucher_type_id', $type->id)
            ->when($yearly, fn ($q) => $q->where('financial_year_id', $fy->id))
            ->count();

        $number = $used + 1;

        // Keep the stored cursor in sync for display/settings (not authoritative — the
        // count above is). Reflects the current scope's next number, incl. after a yearly reset.
        $series->next_number = $number + 1;
        $series->save();

        $padded = str_pad((string) $number, (int) $series->width, '0', STR_PAD_LEFT);
        $yearPart = $yearly ? $fy->start_date->format('Y') . '-' : '';

        return "{$series->prefix}{$yearPart}{$padded}{$series->suffix}";
    }
}
