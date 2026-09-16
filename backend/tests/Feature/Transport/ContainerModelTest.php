<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\ConsignmentContainer;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportContainer;
use App\Models\Transport\TransportOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Block 1 step 3 — the container master and the association, at model level.
 *
 * ── WHY THE NORMALISATION IS TESTED ONE TRANSFORMATION AT A TIME ─────────
 * Every container search this module will ever run goes through
 * TransportContainer::normalise(), and a bug in it is SILENT — the row is
 * there, correct on screen, and the search simply does not find it. A single
 * combined case ("abcd-123 456 7" → "ABCD1234567") would pass even if two of
 * the three transformations were broken in ways that cancelled out.
 */
class ContainerModelTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }
    }

    private function container(string $number, int $tenantId = self::TENANT_A): TransportContainer
    {
        return TransportContainer::create(['tenant_id' => $tenantId, 'container_number' => $number]);
    }

    private function consignment(int $tenantId = self::TENANT_A): TransportConsignment
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);

        return TransportConsignment::create([
            'tenant_id' => $tenantId,
            'consignment_number' => TransportConsignment::nextLocalNumber($tenantId),
            'order_id' => $order->id, 'customer_id' => 1,
        ]);
    }

    /* ══════════ normalisation — one transformation per test ══════════ */

    public function test_normalisation_1_trims_surrounding_whitespace(): void
    {
        // Invisible on screen, and it makes a value unequal to itself.
        $this->assertSame('ABCD1234567', TransportContainer::normalise('  ABCD1234567  '));
        $this->assertSame('ABCD1234567', TransportContainer::normalise("\tABCD1234567\n"));
    }

    public function test_normalisation_2_strips_separators(): void
    {
        // The same box is written several ways by hand and by machine.
        foreach (['ABCD 123456 7', 'ABCD-123456-7', 'ABCD.123456.7', 'ABCD/123456/7'] as $written) {
            $this->assertSame(
                'ABCD1234567', TransportContainer::normalise($written),
                "{$written} must normalise to the same key",
            );
        }
    }

    public function test_normalisation_2b_strips_internal_whitespace_too(): void
    {
        // Subsumes any "collapse spaces" rule — there is no separate one.
        $this->assertSame('ABCD1234567', TransportContainer::normalise("ABCD\t123456  7"));
    }

    public function test_normalisation_3_uppercases(): void
    {
        $this->assertSame('ABCD1234567', TransportContainer::normalise('abcd1234567'));
        $this->assertSame('ABCD1234567', TransportContainer::normalise('AbCd1234567'));
    }

    public function test_normalisation_is_idempotent(): void
    {
        // Applying it twice must not change the answer — otherwise a value
        // normalised on write and again on read would stop matching itself.
        $once = TransportContainer::normalise('abcd-123456-7');

        $this->assertSame($once, TransportContainer::normalise($once));
    }

    public function test_normalisation_does_not_truncate(): void
    {
        // Shortening here would make two different boxes collide on one key.
        $long = str_repeat('A', 15).'123456789';

        $this->assertSame(strlen($long), strlen(TransportContainer::normalise($long)));
    }

    public function test_normalisation_does_not_validate_format(): void
    {
        // CTD §7 asks for "configurable format validation"; no document
        // configures it, and ISO 6346's check digit would reject legitimate
        // non-ISO numbers. Deferred — so nonsense normalises rather than throws.
        $this->assertSame('NOTACONTAINER', TransportContainer::normalise('not-a-container'));
        $this->assertSame('1', TransportContainer::normalise('1'));
    }

    public function test_normalisation_handles_null_and_empty_without_throwing(): void
    {
        // A normaliser that threw would make every read path a try/catch. The
        // database's NOT NULL is what refuses an empty number.
        $this->assertSame('', TransportContainer::normalise(null));
        $this->assertSame('', TransportContainer::normalise('   '));
        $this->assertSame('', TransportContainer::normalise('---'));
    }

    /* ══════════ the normalised key cannot drift from the number ══════════ */

    public function test_saving_derives_the_normalised_key(): void
    {
        $c = $this->container('abcd-123456-7');

        $this->assertSame('abcd-123456-7', $c->container_number, 'CTD §7 — the entered value is retained');
        $this->assertSame('ABCD1234567', $c->container_number_normalized);
    }

    public function test_the_normalised_key_is_not_fillable(): void
    {
        // A caller that could set it independently could break the search for a
        // row that looks perfectly correct on screen.
        $this->assertNotContains('container_number_normalized', (new TransportContainer())->getFillable());
    }

    public function test_updating_the_number_updates_the_key(): void
    {
        $c = $this->container('ABCD1234567');

        $c->update(['container_number' => 'wxyz 765432 1']);

        $this->assertSame('WXYZ7654321', $c->fresh()->container_number_normalized);
    }

    public function test_a_caller_cannot_force_a_mismatched_key(): void
    {
        // Even forceFill loses: the saving hook always recomputes.
        $c = $this->container('ABCD1234567');
        $c->forceFill(['container_number_normalized' => 'WRONG'])->save();

        $this->assertSame('ABCD1234567', $c->fresh()->container_number_normalized);
    }

    /* ══════════ uniqueness — MDM-008 and CTD §7 ══════════ */

    public function test_the_same_box_written_differently_is_refused_as_a_duplicate(): void
    {
        $this->container('ABCD1234567');

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->container('abcd-123456-7');
    }

    public function test_two_tenants_may_hold_the_same_container_number(): void
    {
        $this->container('ABCD1234567', self::TENANT_A);
        $this->container('ABCD1234567', self::TENANT_B);

        $this->assertSame(1, TransportContainer::forTenant(self::TENANT_A)->count());
        $this->assertSame(1, TransportContainer::forTenant(self::TENANT_B)->count());
    }

    /* ══════════ search — CTD-001 ══════════ */

    public function test_search_normalises_the_term_as_well_as_the_column(): void
    {
        $this->container('ABCD1234567');

        foreach (['abcd-123456-7', 'ABCD 123456 7', '  abcd1234567 '] as $typed) {
            $this->assertNotNull(
                TransportContainer::forTenant(self::TENANT_A)->withNumber($typed)->first(),
                "searching '{$typed}' must find the stored row",
            );
        }
    }

    public function test_search_is_exact_not_partial(): void
    {
        // A container number is an identifier; a partial match returns a
        // different box.
        $this->container('ABCD1234567');

        $this->assertNull(TransportContainer::forTenant(self::TENANT_A)->withNumber('ABCD123456')->first());
    }

    public function test_partial_search_is_a_separate_scope(): void
    {
        $this->container('ABCD1234567');

        $this->assertNotNull(
            TransportContainer::forTenant(self::TENANT_A)->numberContains('123456')->first(),
        );
    }

    /* ══════════ tenancy ══════════ */

    public function test_both_models_carry_belongs_to_tenant(): void
    {
        foreach ([TransportContainer::class, ConsignmentContainer::class] as $model) {
            $this->assertContains(
                \App\Models\Traits\BelongsToTenant::class,
                class_uses_recursive($model),
                class_basename($model).' must be tenant-scoped',
            );
        }
    }

    public function test_a_container_is_not_reachable_from_another_tenant(): void
    {
        $c = $this->container('ABCD1234567', self::TENANT_A);

        $this->assertNull(TransportContainer::forTenant(self::TENANT_B)->find($c->id));
    }

    /* ══════════ the attachment relation ══════════ */

    public function test_attaching_links_a_container_to_a_consignment(): void
    {
        $c = $this->container('ABCD1234567');
        $consignment = $this->consignment();

        ConsignmentContainer::create([
            'tenant_id' => self::TENANT_A, 'consignment_id' => $consignment->id,
            'container_id' => $c->id, 'attached_at' => now(),
        ]);

        $this->assertTrue($c->fresh()->isAttached());
        $this->assertCount(1, $consignment->fresh()->containerAttachments);
        $this->assertSame($c->id, $consignment->fresh()->containerAttachments->first()->container->id);
    }

    public function test_detaching_leaves_the_row_as_history(): void
    {
        // STOS-CTD §7 — "maintain historical associations". The row IS the
        // history; there is no delete path.
        $c = $this->container('ABCD1234567');
        $a = ConsignmentContainer::create([
            'tenant_id' => self::TENANT_A, 'consignment_id' => $this->consignment()->id,
            'container_id' => $c->id, 'attached_at' => now()->subDay(),
        ]);

        $a->update(['detached_at' => now()]);

        $this->assertFalse($c->fresh()->isAttached());
        $this->assertNull($c->fresh()->currentAttachment());
        $this->assertCount(1, $c->fresh()->attachments, 'the detached row survives');
    }

    public function test_the_association_has_no_soft_deletes(): void
    {
        $this->assertFalse(Schema::hasColumn('transport_consignment_containers', 'deleted_at'));
    }

    public function test_the_generated_key_is_not_fillable(): void
    {
        // The database computes it. Listing it would let a caller set a value
        // the engine then overwrites — a bug that reads as working code.
        $this->assertNotContains('active_container_key', (new ConsignmentContainer())->getFillable());
    }

    public function test_a_consignment_may_carry_several_containers(): void
    {
        // STOS-CTD §8, verbatim: "contain multiple containers".
        $consignment = $this->consignment();

        foreach (['ABCD1234567', 'WXYZ7654321'] as $number) {
            ConsignmentContainer::create([
                'tenant_id' => self::TENANT_A, 'consignment_id' => $consignment->id,
                'container_id' => $this->container($number)->id, 'attached_at' => now(),
            ]);
        }

        $this->assertCount(2, $consignment->fresh()->containerAttachments);
    }

    public function test_a_consignment_may_carry_none(): void
    {
        // §8's "other cargo references" — break-bulk is not an error.
        $this->assertCount(0, $this->consignment()->containerAttachments);
    }
}
