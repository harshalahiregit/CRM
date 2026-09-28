<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportConsignment;
use App\Support\Numbering\DocumentTypeRegistry;
use App\Support\Transport\TransportDocumentNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Block 1 step 4 — `transport_consignment` on the Document Numbering Engine.
 *
 * The engine is the only allocator this codebase permits: its interface states
 * "Every module MUST allocate through this contract — no module may implement
 * its own numbering." It is also opt-in per tenant, so the module keeps a local
 * fallback for workspaces that have not switched it on.
 *
 * The point of these tests is that those are ONE path with a fallback, not two
 * live allocators. Two things that can both issue a number will eventually
 * issue the same one.
 */
class ConsignmentNumberingTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT_A, 'name' => 'Alpha', 'slug' => 'alpha',
            'subdomain' => 'alpha', 'status' => 'active',
        ])->save();
    }

    public function test_the_type_is_registered_with_the_engine(): void
    {
        $this->assertTrue(
            DocumentTypeRegistry::exists('transport_consignment'),
            'the engine must know the type, or allocation can never be switched on',
        );
    }

    public function test_it_is_registered_at_runtime_not_by_editing_the_shared_registry(): void
    {
        // DocumentTypeRegistry::TYPES is edited by four modules; Transport
        // registers from its own provider instead, which is what keeps this
        // out of everyone else's merge conflicts.
        $source = file_get_contents(app_path('Support/Numbering/DocumentTypeRegistry.php'));

        $this->assertStringNotContainsString('transport_consignment', $source);
        $this->assertStringContainsString(
            "DocumentTypeRegistry::register('transport_consignment'",
            file_get_contents(app_path('Providers/TransportNumberingServiceProvider.php')),
        );
    }

    public function test_the_definition_matches_the_order_and_trip_shape(): void
    {
        $d = DocumentTypeRegistry::all()['transport_consignment'];

        $this->assertSame('Transport', $d['module']);
        $this->assertSame('{PREFIX}-{YYYY}-{NEXT}', $d['format']);
        $this->assertSame('CNM', $d['prefix']);
        $this->assertSame(6, $d['minimum_digits']);
        $this->assertSame('yearly', $d['reset_rule']);
    }

    public function test_all_three_transport_types_share_one_format(): void
    {
        // A module whose three references are formatted three ways teaches the
        // operator nothing about which is which.
        $all = DocumentTypeRegistry::all();

        foreach (['transport_order', 'transport_trip', 'transport_consignment'] as $key) {
            $this->assertSame('{PREFIX}-{YYYY}-{NEXT}', $all[$key]['format'], "{$key} must match");
            $this->assertSame('yearly', $all[$key]['reset_rule']);
            $this->assertSame(6, $all[$key]['minimum_digits']);
        }
    }

    public function test_each_transport_type_keeps_its_own_prefix(): void
    {
        $all = DocumentTypeRegistry::all();

        $prefixes = [
            'transport_order'       => 'TO',
            'transport_trip'        => 'TRP',
            'transport_consignment' => 'CNM',
        ];

        foreach ($prefixes as $key => $prefix) {
            $this->assertSame($prefix, $all[$key]['prefix']);
        }

        // Three distinct prefixes — a shared one would make the reference
        // ambiguous the moment two sequences reached the same ordinal.
        $this->assertCount(3, array_unique($prefixes));
    }

    /* ══════════ one path, with a fallback — not two allocators ══════════ */

    public function test_the_engine_is_tried_first_and_the_local_allocator_is_the_fallback(): void
    {
        // Nothing is switched on for this tenant, so allocate() must fall back
        // rather than fail — the engine is opt-in per tenant by design.
        $number = TransportDocumentNumber::allocate(
            'transport_consignment',
            self::TENANT_A,
            fn () => TransportConsignment::nextLocalNumber(self::TENANT_A),
        );

        $this->assertMatchesRegularExpression('/^CNM-\d{4}-\d{6}$/', $number);
    }

    public function test_the_fallback_produces_the_same_shape_the_engine_would(): void
    {
        // If these two ever diverged, a tenant switching the engine on would
        // see its reference format change under it.
        $definition = DocumentTypeRegistry::all()['transport_consignment'];
        $local      = TransportConsignment::nextLocalNumber(self::TENANT_A);

        [$prefix, $year, $sequence] = explode('-', $local);

        $this->assertSame($definition['prefix'], $prefix);
        $this->assertSame(date('Y'), $year);
        $this->assertSame($definition['minimum_digits'], strlen($sequence));
    }

    public function test_the_year_resets_the_sequence_as_the_rule_says(): void
    {
        // 'yearly'. The local allocator scopes its MAX() to this year's prefix,
        // so a number issued last year cannot hold this year's sequence down.
        TransportConsignment::create([
            'tenant_id'          => self::TENANT_A,
            'consignment_number' => 'CNM-'.(date('Y') - 1).'-000417',
            'order_id'           => 1,
        ]);

        $this->assertStringEndsWith(
            '000001',
            TransportConsignment::nextLocalNumber(self::TENANT_A),
            "last year's sequence must not carry into this year",
        );
    }
}
