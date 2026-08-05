<?php

namespace Tests\Feature\Numbering;

use App\Exceptions\BusinessException;
use App\Models\Numbering\DocumentNumberConfig;
use App\Models\Numbering\DocumentNumberSequence;
use App\Services\Numbering\DatabaseDocumentNumberService;
use App\Services\Numbering\DocumentNumberServiceInterface;
use App\Support\Numbering\DocumentTypeRegistry;
use App\Support\Numbering\Reset\ResetStrategyRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Increment E — the Document Numbering Engine. Covers placeholder expansion,
 * preview/generate separation, every reset strategy, tenant isolation, duplicate
 * prevention, validation and the opt-in guarantee. Does not touch increments A–D.
 *
 * CONCURRENCY NOTE: SQLite compiles lockForUpdate() to an empty string and the
 * suite runs a single in-memory connection, so no test here can prove lock
 * contention — that is a MySQL/Postgres guarantee. What IS proven below is the
 * portable half: sequential uniqueness over many allocations, and that the unique
 * index physically rejects a duplicate (tenant, type, period) row.
 */
class DocumentNumberingEngineTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseDocumentNumberService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(DatabaseDocumentNumberService::class);
    }

    /** DocumentTypeRegistry::$extra is static — never leak a runtime type. */
    protected function tearDown(): void
    {
        DocumentTypeRegistry::flush();
        parent::tearDown();
    }

    /** Enable a type for a tenant (the engine is opt-in). */
    private function enable(int $tenantId, string $type, array $overrides = []): DocumentNumberConfig
    {
        return DocumentNumberConfig::create(array_merge(
            DocumentTypeRegistry::defaults($type),
            ['tenant_id' => $tenantId, 'enabled' => true],
            $overrides,
        ));
    }

    /* ── Registry & DI ───────────────────────────────────────────────────── */

    public function test_registry_exposes_every_required_document_type(): void
    {
        foreach ([
            'invoice', 'quotation', 'estimate', 'credit_note', 'debit_note', 'receipt', 'payment', 'expense',
            'purchase_order', 'purchase_request', 'vendor_bill',
            'grn', 'stock_transfer', 'adjustment',
            'employee', 'offer_letter', 'appointment_letter', 'salary_slip',
            'payroll_run', 'payroll_batch', 'project', 'task', 'ticket',
            'candidate', 'job_requisition',
            'tpv_vendor', 'tpv_worker', 'tpv_kickoff', 'tpv_workforce', 'tpv_ppe',
        ] as $type) {
            $this->assertTrue(DocumentTypeRegistry::exists($type), "missing type {$type}");
        }
    }

    public function test_new_document_types_register_without_touching_the_engine(): void
    {
        DocumentTypeRegistry::register('widget', ['Widget', 'Custom', '{PREFIX}-{NEXT}', 'WID', 3, 'never']);
        $this->assertTrue(DocumentTypeRegistry::exists('widget'));

        $this->enable(1, 'widget');
        $this->assertSame('WID-001', $this->svc->generate(1, 'widget'));
    }

    public function test_interface_resolves_to_the_database_implementation(): void
    {
        $this->assertInstanceOf(
            DatabaseDocumentNumberService::class,
            app(DocumentNumberServiceInterface::class),
        );
    }

    /* ── Placeholder expansion ───────────────────────────────────────────── */

    public function test_placeholder_expansion_covers_every_token(): void
    {
        $this->enable(1, 'invoice', [
            'format' => '{PREFIX}/{YYYY}/{YY}/{MM}/{DD}/{TENANT}/{BRANCH}/{DEPARTMENT}/{NEXT}',
            'prefix' => 'INV', 'minimum_digits' => 4, 'reset_rule' => 'never',
        ]);

        $number = $this->svc->generate(1, 'invoice',
            ['branch' => 'BLR', 'department' => 'FIN'],
            Carbon::create(2026, 3, 9, 12, 0, 0),
        );

        $this->assertSame('INV/2026/26/03/09/1/BLR/FIN/0001', $number);
    }

    public function test_next_is_padded_with_the_configured_width_and_character(): void
    {
        $this->enable(1, 'invoice', ['format' => '{NEXT}', 'minimum_digits' => 6, 'padding' => '0']);
        $this->assertSame('000001', $this->svc->generate(1, 'invoice'));
    }

    public function test_suffix_is_appended_when_not_present_as_a_token(): void
    {
        $this->enable(1, 'invoice', ['format' => '{PREFIX}-{NEXT}', 'prefix' => 'IN', 'suffix' => '-FY', 'minimum_digits' => 2]);
        $this->assertSame('IN-01-FY', $this->svc->generate(1, 'invoice'));
    }

    public function test_financial_year_token_honours_the_fy_start_month(): void
    {
        $this->enable(1, 'invoice', ['format' => '{FY}-{NEXT}', 'minimum_digits' => 2, 'reset_rule' => 'never']);

        // Default FY starts in April: March 2026 is still FY 2025-26.
        $this->assertSame('2025-26-01', $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 3, 31)));
        // April 2026 opens FY 2026-27.
        $this->assertSame('2026-27-02', $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 4, 1)));
    }

    /* ── Preview vs generate ─────────────────────────────────────────────── */

    public function test_preview_never_consumes_a_number(): void
    {
        $this->enable(1, 'invoice');

        $first = $this->svc->preview(1, 'invoice');
        $this->assertSame($first, $this->svc->preview(1, 'invoice'));
        $this->assertSame($first, $this->svc->preview(1, 'invoice'));

        // Nothing was written at all.
        $this->assertDatabaseCount('document_number_sequences', 0);
        // …and the first generated number is exactly what preview promised.
        $this->assertSame($first, $this->svc->generate(1, 'invoice'));
    }

    public function test_preview_reflects_the_next_number_after_generation(): void
    {
        $this->enable(1, 'invoice');
        $this->svc->generate(1, 'invoice');

        $this->assertSame('INV-'.now()->format('Y').'-002', $this->svc->preview(1, 'invoice'));
    }

    /* ── Generation & sequences ──────────────────────────────────────────── */

    public function test_generation_increments_and_respects_starting_number(): void
    {
        $this->enable(1, 'invoice', ['starting_number' => 500]);

        $this->assertSame('INV-'.now()->format('Y').'-500', $this->svc->generate(1, 'invoice'));
        $this->assertSame('INV-'.now()->format('Y').'-501', $this->svc->generate(1, 'invoice'));
    }

    public function test_reserve_returns_number_with_metadata(): void
    {
        $this->enable(1, 'invoice');
        $r = $this->svc->reserve(1, 'invoice');

        $this->assertSame(1, $r['sequence']);
        $this->assertSame('invoice', $r['document_type']);
        $this->assertStringContainsString('INV-', $r['number']);
        $this->assertStringEndsWith(':0', $r['period_key']);
    }

    public function test_sequence_row_is_created_per_period(): void
    {
        $this->enable(1, 'invoice', ['reset_rule' => 'monthly']);

        $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 1, 15));
        $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 2, 15));

        $this->assertDatabaseCount('document_number_sequences', 2);
        $this->assertDatabaseHas('document_number_sequences', ['period_key' => 'M2026-01:0']);
        $this->assertDatabaseHas('document_number_sequences', ['period_key' => 'M2026-02:0']);
    }

    public function test_many_sequential_allocations_are_unique(): void
    {
        $this->enable(1, 'invoice', ['minimum_digits' => 5]);

        $numbers = [];
        for ($i = 0; $i < 100; $i++) {
            $numbers[] = $this->svc->generate(1, 'invoice');
        }

        $this->assertCount(100, array_unique($numbers));
        $this->assertSame('INV-'.now()->format('Y').'-00100', end($numbers));
    }

    /** The portable duplicate guarantee: the unique index, not the (no-op) lock. */
    public function test_unique_index_rejects_a_duplicate_sequence_row(): void
    {
        DocumentNumberSequence::create(['tenant_id' => 1, 'document_type' => 'invoice', 'period_key' => 'Y2026:0', 'current_sequence' => 1]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DocumentNumberSequence::create(['tenant_id' => 1, 'document_type' => 'invoice', 'period_key' => 'Y2026:0', 'current_sequence' => 9]);
    }

    /* ── Reset strategies ────────────────────────────────────────────────── */

    public function test_never_reset_keeps_one_continuous_sequence(): void
    {
        $this->enable(1, 'invoice', ['format' => '{NEXT}', 'minimum_digits' => 3, 'reset_rule' => 'never']);

        $this->assertSame('001', $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 1, 1)));
        $this->assertSame('002', $this->svc->generate(1, 'invoice', [], Carbon::create(2027, 6, 1)));
    }

    public function test_yearly_reset(): void
    {
        $this->enable(1, 'invoice', ['format' => '{NEXT}', 'minimum_digits' => 3, 'reset_rule' => 'yearly']);

        $this->assertSame('001', $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 5, 1)));
        $this->assertSame('002', $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 12, 31)));
        $this->assertSame('001', $this->svc->generate(1, 'invoice', [], Carbon::create(2027, 1, 1)));
    }

    public function test_financial_year_reset(): void
    {
        $this->enable(1, 'invoice', ['format' => '{NEXT}', 'minimum_digits' => 3, 'reset_rule' => 'financial_year']);

        $this->assertSame('001', $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 3, 31))); // FY25-26
        $this->assertSame('001', $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 4, 1)));  // FY26-27
        $this->assertSame('002', $this->svc->generate(1, 'invoice', [], Carbon::create(2027, 3, 31))); // still FY26-27
    }

    public function test_monthly_reset(): void
    {
        $this->enable(1, 'invoice', ['format' => '{NEXT}', 'minimum_digits' => 3, 'reset_rule' => 'monthly']);

        $this->assertSame('001', $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 1, 31)));
        $this->assertSame('001', $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 2, 1)));
    }

    public function test_daily_reset(): void
    {
        $this->enable(1, 'invoice', ['format' => '{NEXT}', 'minimum_digits' => 3, 'reset_rule' => 'daily']);

        // Midday UTC keeps these unambiguous in the tenant timezone too.
        $this->assertSame('001', $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 1, 1, 10, 0)));
        $this->assertSame('002', $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 1, 1, 11, 0)));
        $this->assertSame('001', $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 1, 2, 10, 0)));
    }

    /**
     * Period boundaries are evaluated in the TENANT's timezone (via the Increment-C
     * SettingsFormatter), not UTC. 23:00 UTC on 1 Jan is already 2 Jan in
     * Asia/Kolkata (+5:30), so it belongs to the next day's sequence.
     */
    public function test_period_boundaries_use_the_tenant_timezone(): void
    {
        $this->enable(1, 'invoice', ['format' => '{NEXT}', 'minimum_digits' => 3, 'reset_rule' => 'daily']);

        $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 1, 1, 10, 0));  // 15:30 IST, 1 Jan
        // 23:00 UTC = 04:30 IST on 2 Jan → a NEW day for this tenant.
        $this->assertSame('001', $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 1, 1, 23, 0)));

        $this->assertDatabaseHas('document_number_sequences', ['period_key' => 'D2026-01-01:0']);
        $this->assertDatabaseHas('document_number_sequences', ['period_key' => 'D2026-01-02:0']);
    }

    public function test_manual_reset_only_rolls_over_on_explicit_reset(): void
    {
        $this->enable(1, 'invoice', ['format' => '{NEXT}', 'minimum_digits' => 3, 'reset_rule' => 'manual']);

        $this->assertSame('001', $this->svc->generate(1, 'invoice', [], Carbon::create(2026, 1, 1)));
        $this->assertSame('002', $this->svc->generate(1, 'invoice', [], Carbon::create(2027, 1, 1))); // no auto rollover

        $this->svc->reset(1, 'invoice');
        $this->assertSame('001', $this->svc->generate(1, 'invoice'));
    }

    public function test_reset_is_non_destructive_and_preserves_history(): void
    {
        $this->enable(1, 'invoice');
        $this->svc->generate(1, 'invoice');
        $this->svc->generate(1, 'invoice');

        $this->svc->reset(1, 'invoice');
        $this->svc->generate(1, 'invoice');

        // Old period row still exists with its final value; a NEW row carries the reset sequence.
        $this->assertDatabaseCount('document_number_sequences', 2);
        $this->assertDatabaseHas('document_number_sequences', ['period_key' => 'Y'.now()->format('Y').':0', 'current_sequence' => 2]);
        $this->assertDatabaseHas('document_number_sequences', ['period_key' => 'Y'.now()->format('Y').':1', 'current_sequence' => 1]);
    }

    public function test_reset_can_change_the_starting_number(): void
    {
        $this->enable(1, 'invoice');
        $this->svc->generate(1, 'invoice');

        $this->svc->reset(1, 'invoice', 900);
        $this->assertSame('INV-'.now()->format('Y').'-900', $this->svc->generate(1, 'invoice'));
    }

    public function test_reset_strategy_registry_is_pluggable(): void
    {
        $registry = app(ResetStrategyRegistry::class);
        $this->assertSame(
            ['never', 'yearly', 'financial_year', 'monthly', 'daily', 'manual'],
            $registry->keys(),
        );
        // An unknown rule degrades to 'never' rather than breaking generation.
        $this->assertSame('never', $registry->get('does_not_exist')->key());
    }

    /* ── Tenant isolation ────────────────────────────────────────────────── */

    public function test_tenant_isolation(): void
    {
        $this->enable(1, 'invoice');
        $this->enable(2, 'invoice');

        $this->svc->generate(1, 'invoice');
        $this->svc->generate(1, 'invoice');

        $year = now()->format('Y');
        $this->assertSame("INV-{$year}-003", $this->svc->preview(1, 'invoice'));
        $this->assertSame("INV-{$year}-001", $this->svc->preview(2, 'invoice')); // untouched
        $this->assertSame("INV-{$year}-001", $this->svc->generate(2, 'invoice'));
    }

    public function test_document_types_do_not_share_a_sequence(): void
    {
        $this->enable(1, 'invoice');
        $this->enable(1, 'purchase_order');

        $this->svc->generate(1, 'invoice');
        $this->svc->generate(1, 'invoice');

        $year = now()->format('Y');
        $this->assertSame("PO-{$year}-001", $this->svc->generate(1, 'purchase_order'));
    }

    /* ── Opt-in / backward compatibility ─────────────────────────────────── */

    public function test_engine_is_opt_in_and_refuses_to_generate_when_disabled(): void
    {
        $this->assertFalse($this->svc->isEnabled(1, 'invoice'));

        $this->expectException(BusinessException::class);
        $this->svc->generate(1, 'invoice');
    }

    public function test_unknown_document_type_is_rejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->svc->generate(1, 'not_a_real_type');
    }

    public function test_locked_configuration_cannot_be_reset(): void
    {
        $this->enable(1, 'invoice', ['locked' => true]);

        $this->expectException(BusinessException::class);
        $this->svc->reset(1, 'invoice');
    }

    /* ── Validation ──────────────────────────────────────────────────────── */

    public function test_validation_accepts_a_sound_configuration(): void
    {
        $result = $this->svc->validate([
            'document_type' => 'invoice', 'format' => '{PREFIX}-{YYYY}-{NEXT}',
            'prefix' => 'INV', 'minimum_digits' => 3, 'starting_number' => 1, 'reset_rule' => 'yearly',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
        $this->assertNotNull($result['preview']);
    }

    public function test_validation_reports_all_failures_at_once(): void
    {
        $result = $this->svc->validate([
            'document_type' => 'invoice', 'format' => '{PREFIX}-{BOGUS}-{NEXT}-{NEXT}',
            'prefix' => 'IN@V', 'minimum_digits' => 99, 'starting_number' => 0, 'reset_rule' => 'nope',
        ]);

        $this->assertFalse($result['valid']);
        // Both format problems surface, not just the last one.
        $this->assertStringContainsString('Duplicate placeholder', $result['errors']['format']);
        $this->assertStringContainsString('Unknown placeholder', $result['errors']['format']);
        $this->assertArrayHasKey('prefix', $result['errors']);
        $this->assertArrayHasKey('minimum_digits', $result['errors']);
        $this->assertArrayHasKey('starting_number', $result['errors']);
        $this->assertArrayHasKey('reset_rule', $result['errors']);
    }

    public function test_validation_requires_the_next_placeholder(): void
    {
        $result = $this->svc->validate(['document_type' => 'invoice', 'format' => '{PREFIX}-{YYYY}']);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('{NEXT}', $result['errors']['format']);
    }

    /* ── Events ──────────────────────────────────────────────────────────── */

    public function test_generate_and_reset_dispatch_extension_events(): void
    {
        Event::fake();
        $this->enable(1, 'invoice');

        $this->svc->generate(1, 'invoice');
        Event::assertDispatched(\App\Events\Numbering\BeforeGenerate::class);
        Event::assertDispatched(\App\Events\Numbering\AfterGenerate::class);

        $this->svc->reset(1, 'invoice');
        Event::assertDispatched(\App\Events\Numbering\BeforeReset::class);
        Event::assertDispatched(\App\Events\Numbering\AfterReset::class);
    }

    /* ── decrement_on_delete (opt-in, cannot create duplicates) ──────────── */

    public function test_release_last_is_refused_unless_flag_enabled(): void
    {
        $this->enable(1, 'invoice');
        $r = $this->svc->reserve(1, 'invoice');

        $this->assertFalse($this->svc->releaseLast(1, 'invoice', $r['sequence']));
    }

    /** Both the workspace kill-switch AND the per-type flag are required. */
    public function test_release_last_is_refused_when_the_workspace_kill_switch_is_off(): void
    {
        $this->enable(1, 'invoice', ['decrement_on_delete' => true]);
        $r = $this->svc->reserve(1, 'invoice');

        // allow_decrement_on_delete defaults to false.
        $this->assertFalse($this->svc->releaseLast(1, 'invoice', $r['sequence']));
    }

    public function test_release_last_only_releases_the_newest_number(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set(1, 'numbering', 'allow_decrement_on_delete', true);
        $this->enable(1, 'invoice', ['decrement_on_delete' => true]);
        $first = $this->svc->reserve(1, 'invoice');
        $second = $this->svc->reserve(1, 'invoice');

        // Releasing an older number would risk a duplicate — refused.
        $this->assertFalse($this->svc->releaseLast(1, 'invoice', $first['sequence']));
        // Releasing the newest is allowed, and that number is handed out again.
        $this->assertTrue($this->svc->releaseLast(1, 'invoice', $second['sequence']));
        $this->assertSame($second['number'], $this->svc->generate(1, 'invoice'));
    }
}
