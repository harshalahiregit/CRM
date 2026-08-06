<?php

namespace App\Support\Hr;

use Illuminate\Support\Carbon;

/**
 * The payroll financial year — one definition, used by the TDS engine, the
 * investment declarations and the Form-16 data so they can never disagree about
 * which months belong to which year.
 *
 * The start month is a parameter, not a constant, because the caller reads it from
 * the tenant's `payroll.fy_start_month` setting. It defaults to April only because
 * that is the value the setting itself defaults to.
 */
final class FinancialYear
{
    public function __construct(
        public readonly int $startYear,
        public readonly int $startMonth = 4,
    ) {
    }

    /** The financial year a given date falls in. */
    public static function forDate($date, int $startMonth = 4): self
    {
        $d = Carbon::parse($date);

        return new self((int) $d->month >= $startMonth ? (int) $d->year : (int) $d->year - 1, $startMonth);
    }

    /** From a stored label ("2026-2027"). Returns null when unparseable. */
    public static function fromLabel(?string $label, int $startMonth = 4): ?self
    {
        if (! $label || ! preg_match('/^(\d{4})-(\d{2,4})$/', trim($label), $m)) {
            return null;
        }

        return new self((int) $m[1], $startMonth);
    }

    public function startDate(): Carbon
    {
        return Carbon::create($this->startYear, $this->startMonth, 1)->startOfDay();
    }

    public function endDate(): Carbon
    {
        return $this->startDate()->copy()->addYear()->subDay()->endOfDay();
    }

    /** Canonical stored form: "2026-2027". */
    public function label(): string
    {
        return $this->startYear.'-'.($this->startYear + 1);
    }

    /** Display form: "2026-27". */
    public function shortLabel(): string
    {
        return $this->startYear.'-'.substr((string) ($this->startYear + 1), 2);
    }

    public function contains($date): bool
    {
        $d = Carbon::parse($date);

        return $d->betweenIncluded($this->startDate(), $this->endDate());
    }

    /**
     * 1-based position of a calendar month within this year — April is 1 when the
     * year starts in April. Used to split "already paid" from "still to come".
     */
    public function monthIndex(int $calendarMonth): int
    {
        return (($calendarMonth - $this->startMonth + 12) % 12) + 1;
    }

    /** Months left in the year including the given one; always between 1 and 12. */
    public function remainingMonths(int $calendarMonth): int
    {
        return max(1, min(12, 12 - $this->monthIndex($calendarMonth) + 1));
    }

    /** ['2026-04', '2026-05', …] — the payroll period keys this year covers. */
    public function periods(): array
    {
        $out = [];
        $cursor = $this->startDate();
        for ($i = 0; $i < 12; $i++) {
            $out[] = $cursor->format('Y-m');
            $cursor = $cursor->copy()->addMonth();
        }

        return $out;
    }

    /** Periods strictly BEFORE the given one — i.e. what has actually been paid. */
    public function periodsBefore(string $period): array
    {
        return array_values(array_filter($this->periods(), fn ($p) => $p < $period));
    }
}
