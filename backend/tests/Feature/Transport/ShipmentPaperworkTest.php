<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\ConsignmentContainer;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportContainer;
use App\Models\Transport\TransportDocument;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Services\Transport\ContainerPassportService;
use App\Services\Transport\TransportSearchService;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\TransportDocumentEntity;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LR and DO on the shipment — ORD-005, ORD-006, CTD-004, CTD-005. All P0.
 *
 * ── UNBLOCKED WITHOUT ANYBODY SAYING SO ─────────────────────────────────
 * D-41 ruled on 2026-09-12 that an LR and a DO stay DOCUMENTS rather than
 * getting `transport_lr_records` and `transport_delivery_orders` tables of
 * their own. That ruling needed a consignment to be something a document can be
 * filed against, which was a request to Person 3.
 *
 * He shipped it on 16 September. Our own walk of MS-001 §14 on 18 September
 * still recorded step 3 as "partial — LR/DO blocked on P3", because the register
 * said so and nobody had looked at the code. The lesson is in TEAM-CONTRACTS:
 * check a blocker against the repository, not against the register.
 *
 * ── WHAT EACH REQUIREMENT ACTUALLY ASKS FOR ─────────────────────────────
 *   ORD-005  Capture LR details   · P0 · acceptance "LR traceable"
 *   ORD-006  Capture DO details   · P0 · acceptance "DO traceable"
 *   CTD-004  Link container to LR · P0 · acceptance "LR visible"
 *   CTD-005  Link container to DO · P0 · acceptance "DO visible"
 *
 * "Traceable" is the searching; "visible" is the passport. Both are tested.
 */
class ShipmentPaperworkTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }

        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Ops', 'role' => 'staff',
            'email' => 'o-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** A container on a consignment on a trip — the whole chain. */
    private function chain(int $tenantId = self::TENANT_A): array
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'JNPT'], 'delivery_location' => ['address' => 'Pune'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);
        $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();

        $consignment = TransportConsignment::create([
            'tenant_id' => $tenantId, 'order_id' => $order->id,
            'consignment_number' => 'CNM-'.Str::random(8), 'customer_id' => 1,
        ]);

        $trip = TransportTrip::create([
            'tenant_id' => $tenantId, 'order_id' => $order->id, 'consignment_id' => $consignment->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $trip->forceFill(['status' => TripStatus::APPROVED])->save();

        $container = TransportContainer::create([
            'tenant_id' => $tenantId,
            'container_number' => 'SGOE'.self::uniqueSeq(7),
            'container_number_normalized' => 'SGOE'.self::uniqueSeq(7),
        ]);

        ConsignmentContainer::create([
            'tenant_id' => $tenantId, 'consignment_id' => $consignment->id,
            'container_id' => $container->id, 'attached_at' => now(),
        ]);

        return compact('order', 'consignment', 'trip', 'container');
    }

    private function file(int $consignmentId, string $type, string $number, int $version = 1, int $tenantId = self::TENANT_A): TransportDocument
    {
        return TransportDocument::create([
            'tenant_id' => $tenantId,
            'entity_type' => TransportDocumentEntity::CONSIGNMENT,
            'entity_id' => $consignmentId,
            'document_type' => $type,
            'document_number' => $number,
            'version' => $version,
            'issued_on' => now()->toDateString(),
        ]);
    }

    /* ══════════ CTD-004 / CTD-005 — "LR visible", "DO visible" ══════════ */

    public function test_the_passport_chain_carries_the_lr_and_the_do(): void
    {
        $c = $this->chain();
        $this->file($c['consignment']->id, TransportDocumentType::LR, 'LR-2026-0042');
        $this->file($c['consignment']->id, TransportDocumentType::DELIVERY_ORDER, 'DO-2026-0099');

        $chain = app(ContainerPassportService::class)
            ->forContainer($c['container']->id, self::TENANT_A)['chain'];

        $this->assertSame('LR-2026-0042', $chain['lr']['number']);
        $this->assertSame('DO-2026-0099', $chain['do']['number']);
        $this->assertSame('LR / Bilty', $chain['lr']['label']);
    }

    public function test_a_shipment_with_no_paperwork_shows_no_lr_row_rather_than_a_dash(): void
    {
        // "LR visible" is the acceptance criterion, and a dash is not an LR.
        // ChainRow renders nothing for a null, so null is the right absence.
        $c = $this->chain();

        $chain = app(ContainerPassportService::class)
            ->forContainer($c['container']->id, self::TENANT_A)['chain'];

        $this->assertNull($chain['lr']);
        $this->assertNull($chain['do']);
    }

    public function test_the_chain_shows_the_version_in_force(): void
    {
        // STOS-DOC §26 — a renewal is a NEW VERSION, never an overwrite. The
        // chain must show the one currently in force, not the first ever filed.
        $c = $this->chain();
        $this->file($c['consignment']->id, TransportDocumentType::LR, 'LR-OLD', version: 1);
        $this->file($c['consignment']->id, TransportDocumentType::LR, 'LR-NEW', version: 2);

        $chain = app(ContainerPassportService::class)
            ->forContainer($c['container']->id, self::TENANT_A)['chain'];

        $this->assertSame('LR-NEW', $chain['lr']['number']);
        $this->assertSame(2, $chain['lr']['version']);
    }

    /* ══════════ ORD-005 / ORD-006 — "traceable" means searchable ══════════ */

    public function test_an_lr_number_finds_its_shipment(): void
    {
        // CTD §150 NON-NEGOTIABLE: "LR and DO must be searchable."
        // CTD §4 lists both among the entry points that must lead to the
        // same Digital Passport.
        $c = $this->chain();
        $this->file($c['consignment']->id, TransportDocumentType::LR, 'LR-2026-0042');

        $hit = app(TransportSearchService::class)->resolve('LR-2026-0042', self::TENANT_A);

        $this->assertSame('consignment', $hit['type']);
        $this->assertSame($c['consignment']->id, $hit['id']);
        $this->assertSame('LR / Bilty', $hit['kind']);
        $this->assertStringContainsString('LR-2026-0042', $hit['matched_on']);
    }

    public function test_a_do_number_finds_its_shipment_too(): void
    {
        $c = $this->chain();
        $this->file($c['consignment']->id, TransportDocumentType::DELIVERY_ORDER, 'DO-2026-0099');

        $hit = app(TransportSearchService::class)->resolve('DO-2026-0099', self::TENANT_A);

        $this->assertSame('consignment', $hit['type']);
        $this->assertSame('Delivery Order', $hit['kind']);
    }

    public function test_the_search_is_case_insensitive_but_invents_no_format(): void
    {
        // Case and whitespace, yes. Stripping dashes, NO — unlike a container
        // number there is no normalisation rule for an LR anywhere in the
        // package, and inventing one would assume a format no document defines.
        $c = $this->chain();
        $this->file($c['consignment']->id, TransportDocumentType::LR, 'LR-2026-0042');

        $svc = app(TransportSearchService::class);

        $this->assertNotNull($svc->resolve('lr-2026-0042', self::TENANT_A));
        $this->assertNotNull($svc->resolve('  LR-2026-0042  ', self::TENANT_A));
        $this->assertNull($svc->resolve('LR20260042', self::TENANT_A), 'no invented normalisation');
    }

    public function test_an_invoice_number_does_not_answer_as_a_consignment(): void
    {
        // The same column holds e-way bill and invoice numbers. CTD §4 lists
        // "Invoice Number" as its OWN entry point, so answering an invoice
        // search with a consignment would be guessing at what somebody meant.
        $c = $this->chain();
        $this->file($c['consignment']->id, TransportDocumentType::INVOICE, 'INV-777');

        $this->assertNull(app(TransportSearchService::class)->resolve('INV-777', self::TENANT_A));
    }

    public function test_another_tenants_lr_is_not_found(): void
    {
        $theirs = $this->chain(self::TENANT_B);
        $this->file($theirs['consignment']->id, TransportDocumentType::LR, 'LR-THEIRS', tenantId: self::TENANT_B);

        $this->assertNull(app(TransportSearchService::class)->resolve('LR-THEIRS', self::TENANT_A));
        $this->assertNotNull(app(TransportSearchService::class)->resolve('LR-THEIRS', self::TENANT_B));
    }

    /* ══════════ what a consignment may hold ══════════ */

    public function test_a_consignment_is_offered_its_own_paperwork_and_not_a_vehicles(): void
    {
        $offered = TransportDocumentType::forEntity(TransportDocumentEntity::CONSIGNMENT);

        $this->assertContains(TransportDocumentType::LR, $offered);
        $this->assertContains(TransportDocumentType::DELIVERY_ORDER, $offered);

        // A fitness certificate describes a vehicle and outlives the shipment
        // it happened to be carrying — filing one here would make it expire
        // when the shipment closed. P3's reasoning; asserted so it stays true.
        foreach (['insurance', 'permit', 'fitness'] as $notHere) {
            $this->assertNotContains($notHere, $offered);
        }
    }
}
