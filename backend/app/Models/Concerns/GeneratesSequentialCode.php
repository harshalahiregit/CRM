<?php

namespace App\Models\Concerns;

/**
 * Next reference number for a tenant, derived from what has actually been
 * issued rather than from a row count.
 *
 * Forty-one models across every module generate their reference the same way:
 *
 *     $n = static::where('tenant_id', ...)->count() + 1;
 *
 * That only equals the highest number issued while every row ever created is
 * still in the table. Hard-delete one and the count falls behind, so the
 * generator hands back a code that already exists — and because these columns
 * carry a UNIQUE index, the insert does not quietly duplicate, it FAILS. The
 * feature stops working entirely, for everyone, until someone notices.
 *
 * It is not hypothetical: it took out worker registration (tpv_workers), and
 * then vendor creation (vendors.vendor_code) on the very next attempt.
 *
 * This reads the highest suffix already issued under the same prefix and steps
 * past it, so a gap left by a delete is simply skipped. Deleted rows are
 * included on purpose — a code that has been used, even by a row now gone,
 * must never be handed out again: it may be printed on a badge, quoted in an
 * e-mail, or referenced by another record.
 */
trait GeneratesSequentialCode
{
    /**
     * @param  string  $prefix  everything before the number, e.g. "WRK-2026-"
     * @param  int     $pad     digits to zero-pad to
     */
    public static function nextSequentialCode(string $column, string $prefix, int $tenantId, int $pad = 3): string
    {
        $query = static::query();

        // Soft-deleting models must consider trashed rows: their codes are still
        // taken, and the unique index still enforces them.
        if (method_exists(static::class, 'bootSoftDeletes')) {
            $query->withTrashed();
        }

        $highest = $query
            ->where('tenant_id', $tenantId)
            ->where($column, 'like', $prefix.'%')
            ->pluck($column)
            ->map(fn ($code) => (int) substr((string) $code, strlen($prefix)))
            ->max();

        return $prefix.str_pad((string) (((int) $highest) + 1), $pad, '0', STR_PAD_LEFT);
    }
}
