<?php

namespace Tests\Feature\Transport;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportOrder;
use App\Models\User;
use App\Services\Transport\TransportDocumentService;
use App\Support\Transport\TransportDocumentEntity;
use App\Support\Transport\TransportDocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Documents can be filed against a consignment — D-41, and P1's request.
 *
 * D-41 ruled that LR and DO stay documents rather than getting tables of their
 * own. That ruling only means something if a consignment is a thing a document
 * can be filed against, which it was not: `entityTypeFor()` threw before the
 * type check was ever reached, and four P0 requirements — ORD-005, ORD-006,
 * CTD-004, CTD-005 — had nowhere to write.
 *
 * The change P1 asked for was three lines. It needed a fourth: `forEntity()`
 * answered "driver, or else vehicle", so a consignment would have been offered
 * insurance and fitness certificates and refused an LR. That exclusion is the
 * substance of these tests, not an edge case — a vehicle's fitness certificate
 * outlives the shipment it happened to be carrying, and filing it against the
 * consignment would retire it when the shipment closed.
 */
class ConsignmentDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;

    private TransportDocumentService $docs;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT_A, 'name' => 'Alpha', 'slug' => 'alpha',
            'subdomain' => 'alpha', 'status' => 'active',
        ])->save();

        $this->docs = app(TransportDocumentService::class);

        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Ops', 'role' => 'staff',
            'internal_role' => 'transport_dispatcher',
            'email' => 'ops-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function consignment(): TransportConsignment
    {
        $order = TransportOrder::create([
            'tenant_id' => self::TENANT_A, 'order_number' => 'TO-'.Str::random(8),
            'customer_id' => 7,
            'pickup_location' => ['address' => 'JNPT'],
            'delivery_location' => ['address' => 'Bhiwandi'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);

        return TransportConsignment::create([
            'tenant_id' => self::TENANT_A,
            'consignment_number' => 'CN-'.Str::random(8),
            'order_id' => $order->id,
            'customer_id' => 7,
            'cargo_description' => 'Pharma, 2-8C',
        ]);
    }

    /* ══════════ the four P0 requirements that had nowhere to write ══════════ */

    /** ORD-005 / CTD-004 — an LR belongs to the shipment, not to the truck. */
    public function test_an_lr_can_be_filed_against_a_consignment(): void
    {
        $document = $this->docs->file(
            $this->consignment(), TransportDocumentType::LR, [], self::TENANT_A, $this->actor
        );

        $this->assertSame(TransportDocumentEntity::CONSIGNMENT, $document->entity_type);
        $this->assertSame(TransportDocumentType::LR, $document->document_type);
    }

    /** ORD-006 / CTD-005 — the approved ENUM-006 addition, in use. */
    public function test_a_delivery_order_can_be_filed_against_a_consignment(): void
    {
        $document = $this->docs->file(
            $this->consignment(), TransportDocumentType::DELIVERY_ORDER, [], self::TENANT_A, $this->actor
        );

        $this->assertSame(TransportDocumentEntity::CONSIGNMENT, $document->entity_type);
        $this->assertSame('delivery_order', $document->document_type);
        $this->assertSame('Delivery Order', $document->typeLabel());
    }

    /** The rest of a shipment's own paperwork. */
    public function test_the_shipments_other_paperwork_is_accepted(): void
    {
        foreach ([TransportDocumentType::EWAYBILL, TransportDocumentType::INVOICE, TransportDocumentType::POD] as $type) {
            $document = $this->docs->file(
                $this->consignment(), $type, [], self::TENANT_A, $this->actor
            );

            $this->assertSame($type, $document->document_type);
        }
    }

    /* ══════════ the exclusions, which are the point ══════════ */

    /**
     * A vehicle's papers do not belong to a shipment.
     *
     * Had forEntity() kept its "or else vehicle" default, every one of these
     * would have been accepted and a fitness certificate would have expired
     * along with the consignment carrying it.
     */
    public function test_vehicle_and_driver_papers_are_refused_on_a_consignment(): void
    {
        foreach ([
            TransportDocumentType::FITNESS,
            TransportDocumentType::INSURANCE,
            TransportDocumentType::PERMIT,
            TransportDocumentType::VEHICLE_DOC,
            TransportDocumentType::DRIVER_DOC,
        ] as $type) {
            try {
                $this->docs->file($this->consignment(), $type, [], self::TENANT_A, $this->actor);
                $this->fail(TransportDocumentType::label($type).' was accepted against a consignment');
            } catch (BusinessException $e) {
                $this->assertStringContainsString('cannot be filed against a consignment', $e->getMessage());
            }
        }
    }

    /** An LR still has no business being filed against a truck. */
    public function test_the_consignment_types_are_not_leaked_onto_vehicles(): void
    {
        $this->assertNotContains(TransportDocumentType::LR, TransportDocumentType::VEHICLE_APPLICABLE);
        $this->assertNotContains(TransportDocumentType::DELIVERY_ORDER, TransportDocumentType::VEHICLE_APPLICABLE);
        $this->assertNotContains(TransportDocumentType::DELIVERY_ORDER, TransportDocumentType::DRIVER_APPLICABLE);
    }

    /* ══════════ guards on the vocabulary itself ══════════ */

    /**
     * ENUM-006 unchanged, plus exactly one approved addition.
     *
     * If this fails, either the registry was edited without approval or an
     * approval landed that this file has not recorded. Both need a person.
     */
    public function test_enum_006_is_intact_and_delivery_order_is_the_only_addition(): void
    {
        $registry = ['lr', 'ewaybill', 'invoice', 'pod', 'driver_doc',
                     'vehicle_doc', 'insurance', 'permit', 'fitness', 'other'];

        $this->assertSame(
            $registry,
            array_slice(TransportDocumentType::ALL, 0, 10),
            'ENUM-006 must appear first, in registry order'
        );

        $this->assertSame(
            ['delivery_order'],
            array_values(array_diff(TransportDocumentType::ALL, $registry)),
            'delivery_order is the only value approved beyond ENUM-006'
        );

        foreach (TransportDocumentType::ALL as $type) {
            $this->assertNotSame($type, TransportDocumentType::label($type), "{$type} has no label");
        }
    }

    /** The vehicle and driver branches behave exactly as they did before. */
    public function test_the_existing_entities_are_unchanged_by_the_new_branch(): void
    {
        $this->assertSame(
            [TransportDocumentType::VEHICLE_DOC, TransportDocumentType::INSURANCE,
             TransportDocumentType::PERMIT, TransportDocumentType::FITNESS, TransportDocumentType::OTHER],
            TransportDocumentType::forEntity(TransportDocumentEntity::VEHICLE)
        );

        $this->assertSame(
            [TransportDocumentType::DRIVER_DOC, TransportDocumentType::FITNESS,
             TransportDocumentType::PERMIT, TransportDocumentType::OTHER],
            TransportDocumentType::forEntity(TransportDocumentEntity::DRIVER)
        );
    }

    /**
     * An entity nobody has decided about gets nothing.
     *
     * CUSTOMER is declared in DB-019's description but has no ticket. Under the
     * old ternary it silently inherited the vehicle list; now it refuses, which
     * is what an undecided entity should do.
     */
    public function test_an_entity_without_a_ticket_allows_no_document_types(): void
    {
        $this->assertSame([], TransportDocumentType::forEntity(TransportDocumentEntity::CUSTOMER));
        $this->assertSame([], TransportDocumentType::forEntity('spaceship'));
    }

    /** Consignment is live, so it belongs in ACTIVE and not only in ALL. */
    public function test_consignment_is_a_declared_and_active_entity(): void
    {
        $this->assertTrue(TransportDocumentEntity::isValid(TransportDocumentEntity::CONSIGNMENT));
        $this->assertContains(TransportDocumentEntity::CONSIGNMENT, TransportDocumentEntity::ACTIVE);
    }
}
