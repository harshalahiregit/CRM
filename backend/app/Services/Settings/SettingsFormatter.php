<?php

namespace App\Services\Settings;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The single formatting authority for money, numbers and dates.
 *
 * Every module (Finance, Payroll, Sales, Purchase, Inventory, Reports, PDF,
 * email templates) MUST format through this service rather than implementing its
 * own — that is what keeps a tenant's currency/date preferences consistent
 * everywhere. It reads the `currency` and `localization` groups via
 * SettingsService, so it is tenant-aware and cached for free.
 *
 * Usage:
 *   app(SettingsFormatter::class)->money($tenantId, 1234.5);   // ₹1,234.50
 *   app(SettingsFormatter::class)->date($tenantId, $model->created_at);
 */
class SettingsFormatter
{
    public function __construct(private SettingsService $settings)
    {
    }

    /* ── Currency / numbers ──────────────────────────────────────────────── */

    /** Format an amount using the tenant's currency settings. */
    public function money(int $tenantId, float|int|string|null $amount, array $overrides = []): string
    {
        $c = array_merge($this->settings->getGroup($tenantId, 'currency'), $overrides);
        $value = (float) ($amount ?? 0);
        $negative = $value < 0;

        $number = $this->number($tenantId, abs($value), [
            'decimal_places'      => $c['decimal_places'],
            'decimal_separator'   => $c['decimal_separator'],
            'thousands_separator' => $c['thousands_separator'],
            'grouping'            => $c['grouping'],
        ]);

        $withSymbol = match ($c['symbol_position']) {
            'after'        => $number.$c['symbol'],
            'before_space' => $c['symbol'].' '.$number,
            'after_space'  => $number.' '.$c['symbol'],
            default        => $c['symbol'].$number,
        };

        if (! $negative) {
            return $withSymbol;
        }

        return $c['negative_format'] === 'parentheses' ? '('.$withSymbol.')' : '-'.$withSymbol;
    }

    /** Format a bare number (no symbol) using the tenant's number rules. */
    public function number(int $tenantId, float|int|string|null $value, array $overrides = []): string
    {
        $c = array_merge($this->settings->getGroup($tenantId, 'currency'), $overrides);
        $decimals = (int) $c['decimal_places'];
        $value = (float) ($value ?? 0);

        if (($c['grouping'] ?? 'western') === 'indian') {
            return $this->indianGrouping($value, $decimals, $c['decimal_separator'], $c['thousands_separator']);
        }

        return number_format($value, $decimals, (string) $c['decimal_separator'], (string) $c['thousands_separator']);
    }

    /** The currency settings a client (React/PDF) needs to format identically. */
    public function currencyMeta(int $tenantId): array
    {
        return $this->settings->getGroup($tenantId, 'currency');
    }

    /* ── Dates / times ───────────────────────────────────────────────────── */

    /** Format a date using the tenant's date_format + timezone. */
    public function date(int $tenantId, CarbonInterface|string|null $date): ?string
    {
        $carbon = $this->toCarbon($tenantId, $date);

        return $carbon?->format($this->settings->get($tenantId, 'localization', 'date_format'));
    }

    /** Format a time using the tenant's 12/24-hour preference. */
    public function time(int $tenantId, CarbonInterface|string|null $date): ?string
    {
        $carbon = $this->toCarbon($tenantId, $date);
        $fmt = (string) $this->settings->get($tenantId, 'localization', 'time_format') === '24' ? 'H:i' : 'h:i A';

        return $carbon?->format($fmt);
    }

    /** Date + time in the tenant's formats and timezone. */
    public function dateTime(int $tenantId, CarbonInterface|string|null $date): ?string
    {
        $carbon = $this->toCarbon($tenantId, $date);
        if (! $carbon) {
            return null;
        }

        return $this->date($tenantId, $carbon).' '.$this->time($tenantId, $carbon);
    }

    public function timezone(int $tenantId): string
    {
        return (string) $this->settings->get($tenantId, 'localization', 'timezone');
    }

    /** The localization settings a client needs to format identically. */
    public function localizationMeta(int $tenantId): array
    {
        return $this->settings->getGroup($tenantId, 'localization');
    }

    /* ── internals ───────────────────────────────────────────────────────── */

    private function toCarbon(int $tenantId, CarbonInterface|string|null $date): ?CarbonInterface
    {
        if ($date === null || $date === '') {
            return null;
        }
        try {
            $carbon = $date instanceof CarbonInterface ? $date->copy() : Carbon::parse($date);

            return $carbon->setTimezone($this->timezone($tenantId));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Indian digit grouping (1,23,456.78): the last three digits group normally,
     * everything above that groups in pairs.
     */
    private function indianGrouping(float $value, int $decimals, string $dec, string $thousands): string
    {
        $formatted = number_format($value, $decimals, '.', '');
        [$int, $frac] = array_pad(explode('.', $formatted), 2, null);

        $sign = str_starts_with($int, '-') ? '-' : '';
        $int = ltrim($int, '-');

        if (strlen($int) > 3) {
            $last3 = substr($int, -3);
            $rest = substr($int, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', $thousands, $rest);
            $int = $rest.$thousands.$last3;
        }

        return $sign.$int.($decimals > 0 && $frac !== null ? $dec.$frac : '');
    }
}
