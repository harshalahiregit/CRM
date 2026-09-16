<?php

namespace Tests\Feature\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Tenant;
use App\Models\Transport\ConsignmentContainer;
use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportContainer;
use App\Models\Transport\TransportOrder;
use App\Models\User;
use App\Services\Transport\ContainerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Block 1 step 5 — ContainerService.
 *
 * Covers MDM-008 and STOS-CTD §8 behaviourally. The refusals carry most of the
 * weight: a service that attaches correctly but accepts another tenant's
 * container is a data leak every happy-path assertion would pass.
 */
class ContainerServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private ContainerService $service;
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

        $this->service = app(ContainerService::class);

        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Ops', 'role' => 'staff',
            'internal_role' => 'transport_dispatcher',
            'email' => 'ops-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
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

    /* ══════════ create — MDM-008 ══════════ */

    public function test_creating_stores_the_entered_value_and_derives_the_key(): void
    {
        $c = $this->service->create(['container_number' => 'abcd-123456-7'], self::TENANT_A, $this->actor);

        $this->assertSame('abcd-123456-7', $c->container_number);
        $this->assertSame('ABCD1234567', $c->container_number_normalized);
        $this->assertSame($this->actor->id, $c->created_by);
    }

    public function test_creation_is_audited(): void
    {
        $c = $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);

        $entry = TransportAuditLog::forTenant(self::TENANT_A)
            ->forSubject(TransportContainer::class, $c->id)
            ->where('action', 'transport.container.created')->sole();

        $this->assertSame('ABCD1234567', $entry->new_values['normalized']);
    }

    /* ══════════ the refusals ══════════ */

    public function test_two_numbers_that_normalise_identically_are_refused(): void
    {
        // The headline duplicate case: they LOOK different on screen.
        $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);

        try {
            $this->service->create(['container_number' => 'abcd-123456-7'], self::TENANT_A, $this->actor);
            $this->fail('a container differing only in spelling was accepted');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
            // The message must explain WHY, or the user sees two different
            // strings and a refusal that looks wrong.
            $this->assertStringContainsString('same number', $e->getMessage());
        }

        $this->assertSame(1, TransportContainer::forTenant(self::TENANT_A)->count());
    }

    public function test_an_empty_or_punctuation_only_number_is_refused(): void
    {
        foreach (['', '   ', '---'] as $junk) {
            try {
                $this->service->create(['container_number' => $junk], self::TENANT_A, $this->actor);
                $this->fail("'{$junk}' was accepted as a container number");
            } catch (BusinessException $e) {
                $this->assertStringContainsString('required', $e->getMessage());
            }
        }

        $this->assertSame(0, TransportContainer::count());
    }

    public function test_an_unknown_container_is_not_found(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $this->service->find(999999, self::TENANT_A);
    }

    public function test_another_tenants_container_reads_as_no_such_container(): void
    {
        // Never "not yours" — that confirms the row exists elsewhere.
        $c = $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);

        $this->expectException(ResourceNotFoundException::class);

        $this->service->find($c->id, self::TENANT_B);
    }

    public function test_searching_by_number_across_tenants_finds_nothing(): void
    {
        $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);

        $this->expectException(ResourceNotFoundException::class);

        $this->service->findByNumber('ABCD1234567', self::TENANT_B);
    }

    public function test_history_for_another_tenants_container_is_refused(): void
    {
        // Not an empty history — that reads as "this container has no history".
        $c = $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);

        $this->expectException(ResourceNotFoundException::class);

        $this->service->history($c->id, self::TENANT_B);
    }

    public function test_attaching_to_another_tenants_consignment_is_refused(): void
    {
        $c = $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);
        $foreign = $this->consignment(self::TENANT_B);

        $this->expectException(ResourceNotFoundException::class);

        $this->service->attach($c, $foreign, self::TENANT_A, $this->actor);
    }

    public function test_a_refused_create_writes_nothing_at_all(): void
    {
        $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);
        $auditBefore = TransportAuditLog::count();

        try {
            $this->service->create(['container_number' => 'abcd 123456 7'], self::TENANT_A, $this->actor);
        } catch (BusinessException) {
            // expected
        }

        $this->assertSame(1, TransportContainer::count());
        $this->assertSame($auditBefore, TransportAuditLog::count());
    }

    public function test_a_refused_attach_writes_nothing_at_all(): void
    {
        $c = $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);
        $this->service->attach($c, $this->consignment(), self::TENANT_A, $this->actor);

        $rowsBefore  = ConsignmentContainer::count();
        $auditBefore = TransportAuditLog::count();

        try {
            $this->service->attach($c, $this->consignment(), self::TENANT_A, $this->actor);
        } catch (BusinessException) {
            // expected
        }

        $this->assertSame($rowsBefore, ConsignmentContainer::count());
        $this->assertSame($auditBefore, TransportAuditLog::count());
    }

    /* ══════════ §7's one-at-a-time rule ══════════ */

    public function test_attaching_a_container_already_carried_elsewhere_is_refused(): void
    {
        $c = $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);
        $first = $this->consignment();
        $this->service->attach($c, $first, self::TENANT_A, $this->actor);

        try {
            $this->service->attach($c, $this->consignment(), self::TENANT_A, $this->actor);
            $this->fail('a container was attached to two consignments at once');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('already on', $e->getMessage());
            // The message names WHERE it is, so the user can go and detach it.
            $this->assertStringContainsString($first->consignment_number, $e->getMessage());
            $this->assertStringContainsString('cannot be on two consignments at once', $e->getMessage());
        }
    }

    public function test_the_DATABASE_refuses_it_even_with_the_service_check_bypassed(): void
    {
        // THE POINT OF THIS TEST. The service checks first so the user gets a
        // sentence, but the check is not the guarantee — two concurrent requests
        // both pass it. This bypasses the service entirely and asserts the
        // constraint holds on its own, so nobody later removes the index
        // believing the check is enough.
        $c = $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);
        $this->service->attach($c, $this->consignment(), self::TENANT_A, $this->actor);

        $this->expectException(QueryException::class);

        DB::table('transport_consignment_containers')->insert([
            'tenant_id'      => self::TENANT_A,
            'consignment_id' => $this->consignment()->id,
            'container_id'   => $c->id,
            'attached_at'    => now(),
            'detached_at'    => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function test_two_tenants_may_each_attach_their_own_container(): void
    {
        $a = $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);
        $b = $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_B, $this->actor);

        $this->service->attach($a, $this->consignment(self::TENANT_A), self::TENANT_A, $this->actor);
        $this->service->attach($b, $this->consignment(self::TENANT_B), self::TENANT_B, $this->actor);

        $this->assertSame(2, ConsignmentContainer::count());
    }

    public function test_one_consignment_may_carry_several_containers(): void
    {
        // STOS-CTD §8 — "contain one container; contain multiple containers".
        // The uniqueness rule is one CONSIGNMENT per container, never one
        // container per consignment. Without this test, someone reading the
        // unique index could "tighten" it to the consignment side and every
        // other test here would still pass.
        $consignment = $this->consignment();

        foreach (['AAAA1111111', 'BBBB2222222', 'CCCC3333333'] as $number) {
            $c = $this->service->create(['container_number' => $number], self::TENANT_A, $this->actor);
            $this->service->attach($c, $consignment, self::TENANT_A, $this->actor);
        }

        $this->assertCount(3, $this->service->attachmentsFor($consignment, self::TENANT_A));
    }

    public function test_listing_another_tenants_consignment_contents_is_refused(): void
    {
        // An empty list would read as "that consignment is empty", which is a
        // different and wrong answer.
        $foreign = $this->consignment(self::TENANT_B);

        $this->expectException(ResourceNotFoundException::class);

        $this->service->attachmentsFor($foreign, self::TENANT_A);
    }

    /* ══════════ detach ══════════ */

    public function test_detaching_keeps_the_row_as_history(): void
    {
        $c = $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);
        $this->service->attach($c, $this->consignment(), self::TENANT_A, $this->actor);

        $detached = $this->service->detach($c, self::TENANT_A, $this->actor);

        $this->assertNotNull($detached->detached_at);
        $this->assertSame(1, ConsignmentContainer::count(), 'the row survives — it IS the history');
        $this->assertFalse($c->fresh()->isAttached());
    }

    public function test_detaching_something_not_attached_is_refused(): void
    {
        $c = $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('is not attached to a consignment');

        $this->service->detach($c, self::TENANT_A, $this->actor);
    }

    public function test_detaching_twice_is_refused(): void
    {
        $c = $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);
        $this->service->attach($c, $this->consignment(), self::TENANT_A, $this->actor);
        $this->service->detach($c, self::TENANT_A, $this->actor);

        $this->expectException(BusinessException::class);

        $this->service->detach($c, self::TENANT_A, $this->actor);
    }

    public function test_detaching_across_tenants_is_a_404(): void
    {
        $c = $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);
        $this->service->attach($c, $this->consignment(), self::TENANT_A, $this->actor);

        $this->expectException(ResourceNotFoundException::class);

        $this->service->detach($c, self::TENANT_B, $this->actor);
    }

    public function test_reuse_after_detach_is_allowed_and_both_rows_survive(): void
    {
        // STOS-CTD §7 — "allowed historically but not simultaneously", the
        // whole rule in one test.
        $c = $this->service->create(['container_number' => 'ABCD1234567'], self::TENANT_A, $this->actor);

        $this->service->attach($c, $this->consignment(), self::TENANT_A, $this->actor);
        $this->service->detach($c, self::TENANT_A, $this->actor);
        $this->service->attach($c, $this->consignment(), self::TENANT_A, $this->actor);

        $this->assertSame(2, ConsignmentContainer::forTenant(self::TENANT_A)->forContainer($c->id)->count());
        $this->assertSame(1, ConsignmentContainer::forTenant(self::TENANT_A)->forContainer($c->id)->active()->count());
        $this->assertCount(2, $this->service->history($c->id, self::TENANT_A));
    }

    /* ══════════ list ══════════ */

    public function test_the_list_never_crosses_tenants(): void
    {
        $this->service->create(['container_number' => 'AAAA1111111'], self::TENANT_A, $this->actor);
        $this->service->create(['container_number' => 'BBBB2222222'], self::TENANT_B, $this->actor);

        $this->assertSame(1, $this->service->list(self::TENANT_A)->total());
        $this->assertSame(1, $this->service->list(self::TENANT_B)->total());
    }

    public function test_the_list_can_filter_by_whether_it_is_on_a_consignment(): void
    {
        $on  = $this->service->create(['container_number' => 'AAAA1111111'], self::TENANT_A, $this->actor);
        $this->service->create(['container_number' => 'BBBB2222222'], self::TENANT_A, $this->actor);
        $this->service->attach($on, $this->consignment(), self::TENANT_A, $this->actor);

        $this->assertSame(1, $this->service->list(self::TENANT_A, ['attached' => true])->total());
        $this->assertSame(1, $this->service->list(self::TENANT_A, ['attached' => false])->total());
    }

    public function test_the_page_size_is_clamped(): void
    {
        $this->assertSame(200, $this->service->list(self::TENANT_A, ['per_page' => 99999])->perPage());
        $this->assertSame(1, $this->service->list(self::TENANT_A, ['per_page' => 0])->perPage());
    }
}
