<?php

namespace Tests\Feature\Settings;

use App\Services\Settings\SettingsFormatter;
use App\Services\Settings\SettingsService;
use App\Support\Settings\SettingRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Increment C — Localization + Currency groups and the shared SettingsFormatter
 * that every module formats through. Does not touch increments A/B/D.
 */
class LocalizationCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private SettingsService $svc;

    private SettingsFormatter $fmt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SettingsService();
        $this->fmt = new SettingsFormatter($this->svc);
    }

    /* ── registry / store ────────────────────────────────────────────── */

    public function test_groups_registered_with_defaults(): void
    {
        $this->assertTrue(SettingRegistry::groupExists('localization'));
        $this->assertTrue(SettingRegistry::groupExists('currency'));

        $this->assertSame('Asia/Kolkata', SettingRegistry::defaults('localization')['timezone']);
        $this->assertSame('d/m/Y', SettingRegistry::defaults('localization')['date_format']);
        $this->assertSame('INR', SettingRegistry::defaults('currency')['code']);
        $this->assertSame(2, SettingRegistry::defaults('currency')['decimal_places']);
        $this->assertSame('indian', SettingRegistry::defaults('currency')['grouping']);
    }

    public function test_rules_exist_for_both_groups(): void
    {
        $rules = SettingRegistry::rules(['localization', 'currency']);
        $this->assertArrayHasKey('localization.timezone', $rules);
        $this->assertArrayHasKey('currency.decimal_places', $rules);
        $this->assertContains('timezone', $rules['localization.timezone']);
    }

    public function test_settings_roundtrip_and_tenant_isolation(): void
    {
        $this->svc->setGroup(1, 'currency', ['code' => 'USD', 'symbol' => '$', 'grouping' => 'western']);

        $this->assertSame('USD', $this->svc->getGroup(1, 'currency')['code']);
        $this->assertSame(2, $this->svc->getGroup(1, 'currency')['decimal_places']); // untouched default
        $this->assertSame('INR', $this->svc->getGroup(2, 'currency')['code']);       // other tenant = default
    }

    /* ── formatter: money / numbers ──────────────────────────────────── */

    public function test_money_uses_indian_grouping_by_default(): void
    {
        $this->assertSame('₹12,34,567.89', $this->fmt->money(1, 1234567.891));
        $this->assertSame('₹1,234.50', $this->fmt->money(1, 1234.5));
        $this->assertSame('₹0.00', $this->fmt->money(1, 0));
    }

    public function test_money_respects_western_grouping_and_symbol_position(): void
    {
        $this->svc->setGroup(1, 'currency', [
            'code' => 'USD', 'symbol' => '$', 'grouping' => 'western', 'symbol_position' => 'after_space',
        ]);

        $this->assertSame('1,234,567.89 $', $this->fmt->money(1, 1234567.891));
    }

    public function test_negative_formats(): void
    {
        $this->assertSame('-₹1,234.50', $this->fmt->money(1, -1234.5));

        $this->svc->setGroup(1, 'currency', ['negative_format' => 'parentheses']);
        $this->assertSame('(₹1,234.50)', $this->fmt->money(1, -1234.5));
    }

    public function test_separators_and_decimal_places(): void
    {
        $this->svc->setGroup(1, 'currency', [
            'grouping' => 'western', 'decimal_separator' => ',', 'thousands_separator' => '.', 'decimal_places' => 3,
        ]);

        $this->assertSame('1.234.567,891', $this->fmt->number(1, 1234567.891));
    }

    public function test_number_has_no_symbol(): void
    {
        $this->assertSame('12,34,567.89', $this->fmt->number(1, 1234567.891));
    }

    /* ── formatter: dates ────────────────────────────────────────────── */

    public function test_date_and_time_use_tenant_formats_and_timezone(): void
    {
        // 10:15 UTC → 15:45 Asia/Kolkata (+5:30)
        $this->assertSame('09/03/2026', $this->fmt->date(1, '2026-03-09 10:15:00'));
        $this->assertSame('03:45 PM', $this->fmt->time(1, '2026-03-09 10:15:00'));

        $this->svc->setGroup(1, 'localization', ['date_format' => 'Y-m-d', 'time_format' => '24']);
        $this->assertSame('2026-03-09', $this->fmt->date(1, '2026-03-09 10:15:00'));
        $this->assertSame('15:45', $this->fmt->time(1, '2026-03-09 10:15:00'));
        $this->assertSame('2026-03-09 15:45', $this->fmt->dateTime(1, '2026-03-09 10:15:00'));
    }

    public function test_timezone_change_shifts_output(): void
    {
        $this->svc->setGroup(1, 'localization', ['timezone' => 'UTC', 'time_format' => '24']);
        $this->assertSame('10:15', $this->fmt->time(1, '2026-03-09 10:15:00'));
    }

    public function test_null_and_invalid_dates_are_safe(): void
    {
        $this->assertNull($this->fmt->date(1, null));
        $this->assertNull($this->fmt->time(1, ''));
        $this->assertNull($this->fmt->dateTime(1, 'not-a-date'));
    }

    public function test_meta_helpers_expose_the_contract(): void
    {
        $this->assertSame('INR', $this->fmt->currencyMeta(1)['code']);
        $this->assertSame('Asia/Kolkata', $this->fmt->localizationMeta(1)['timezone']);
        $this->assertSame('Asia/Kolkata', $this->fmt->timezone(1));
    }
}
