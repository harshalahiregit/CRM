<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\ConsignmentContainer;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportContainer;
use App\Models\Transport\TransportOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Container API — Block 1, step 6.
 *
 * Seven endpoints, none in Step 11's API registry and none owned by a Step 12
 * ticket (D-45, same position as Consignment). Covers MDM-008 and STOS-CTD §7
 * and §8 over HTTP.
 *
 * The refusals carry the weight. An endpoint that creates correctly but answers
 * for another tenant is a leak every happy-path assertion would pass.
 */
class ContainerApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $n) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $n, 'slug' => Str::slug($n),
                'subdomain' => Str::slug($n), 'status' => 'active',
            ])->save();
        }
    }

    private function user(int $tenantId = self::TENANT_A, string $role = 'admin', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role), 'role' => $role, 'internal_role' => $internal,
            'email' => $role.'-'.Str::random(8).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function signIn(int $tenantId = self::TENANT_A, string $role = 'admin', ?string $internal = null): User
    {
        $u = $this->user($tenantId, $role, $internal);
        Sanctum::actingAs($u);

        return $u;
    }

    private function consignment(int $tenantId = self::TENANT_A): TransportConsignment
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 7,
            'pickup_location' => ['address' => 'JNPT'], 'delivery_location' => ['address' => 'Bhiwandi'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);

        return TransportConsignment::create([
            'tenant_id' => $tenantId,
            'consignment_number' => TransportConsignment::nextLocalNumber($tenantId),
            'order_id' => $order->id, 'customer_id' => 7,
        ]);
    }

    private function container(int $tenantId = self::TENANT_A, string $number = 'ABCD1234567'): TransportContainer
    {
        return TransportContainer::create(['tenant_id' => $tenantId, 'container_number' => $number]);
    }

    /* ══════════ create ══════════ */

    public function test_a_container_can_be_created_and_read_back(): void
    {
        $this->signIn();

        $created = $this->postJson('/api/transport/containers', [
            'container_number' => 'abcd-123456-7', 'container_type' => '40ft HC',
        ])->assertCreated()->json('data');

        // §7 — the entered value is retained AND a normalised key is derived.
        $this->assertSame('abcd-123456-7', $created['container_number']);
        $this->assertSame('ABCD1234567', $created['container_number_normalized']);

        $this->getJson('/api/transport/containers/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('data.container.container_number', 'abcd-123456-7')
            ->assertJsonPath('data.history', []);
    }

    public function test_creation_requires_a_container_number(): void
    {
        $this->signIn();

        $this->postJson('/api/transport/containers', ['container_type' => '40ft'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('container_number');
    }

    public function test_a_number_that_normalises_to_an_existing_one_is_refused(): void
    {
        $this->signIn();
        $this->postJson('/api/transport/containers', ['container_number' => 'ABCD1234567'])->assertCreated();

        // On screen these are two different strings, so the refusal has to say why.
        $this->postJson('/api/transport/containers', ['container_number' => 'abcd 123456 7'])
            ->assertStatus(422);

        $this->assertSame(1, TransportContainer::count());
    }

    public function test_the_normalised_key_cannot_be_supplied_by_the_caller(): void
    {
        // Supplying a key that does not match the number would put the container
        // beyond CTD-001 search while looking correct on screen.
        $this->signIn();

        $created = $this->postJson('/api/transport/containers', [
            'container_number'            => 'ABCD1234567',
            'container_number_normalized' => 'SOMETHINGELSE',
        ])->assertCreated()->json('data');

        $this->assertSame('ABCD1234567', $created['container_number_normalized']);
    }

    /* ══════════ CTD-001 lookup ══════════ */

    public function test_lookup_finds_a_container_however_the_number_was_typed(): void
    {
        $this->signIn();
        $this->container();

        foreach (['ABCD1234567', 'abcd-123456-7', ' abcd 123456 7 '] as $typed) {
            $this->getJson('/api/transport/containers/lookup?number='.urlencode($typed))
                ->assertOk()
                ->assertJsonPath('data.container_number', 'ABCD1234567');
        }
    }

    public function test_lookup_of_an_unknown_number_is_a_404(): void
    {
        $this->signIn();

        $this->getJson('/api/transport/containers/lookup?number=ZZZZ9999999')->assertNotFound();
    }

    public function test_lookup_requires_a_number(): void
    {
        $this->signIn();

        $this->getJson('/api/transport/containers/lookup')->assertStatus(422);
    }

    /* ══════════ the cross-tenant axis ══════════ */

    public function test_another_tenants_container_is_a_404_on_every_endpoint(): void
    {
        // 404 and not 403 throughout: a 403 confirms the row exists.
        $foreign = $this->container(self::TENANT_B);
        $this->signIn(self::TENANT_A);

        $this->getJson('/api/transport/containers/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/transport/containers/lookup?number=ABCD1234567')->assertNotFound();
        $this->postJson('/api/transport/containers/'.$foreign->id.'/attach', [
            'consignment_id' => $this->consignment(self::TENANT_A)->id,
        ])->assertNotFound();
        $this->postJson('/api/transport/containers/'.$foreign->id.'/detach')->assertNotFound();
    }

    public function test_the_list_never_shows_another_tenants_containers(): void
    {
        $this->container(self::TENANT_B, 'BBBB2222222');
        $this->signIn(self::TENANT_A);
        $this->container(self::TENANT_A, 'AAAA1111111');

        $this->getJson('/api/transport/containers')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.container_number', 'AAAA1111111');
    }

    public function test_attaching_to_another_tenants_consignment_is_refused(): void
    {
        $foreign = $this->consignment(self::TENANT_B);
        $this->signIn(self::TENANT_A);
        $container = $this->container();

        // Caught at validation: the exists rule is tenant-scoped, so it never
        // reaches the service and never confirms the consignment exists.
        $this->postJson('/api/transport/containers/'.$container->id.'/attach', [
            'consignment_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors('consignment_id');

        $this->assertSame(0, ConsignmentContainer::count());
    }

    /* ══════════ attach / detach ══════════ */

    public function test_a_container_can_be_attached_and_detached(): void
    {
        $this->signIn();
        $container   = $this->container();
        $consignment = $this->consignment();

        $this->postJson('/api/transport/containers/'.$container->id.'/attach', [
            'consignment_id' => $consignment->id,
        ])->assertCreated()->assertJsonPath('data.consignment_id', $consignment->id);

        $this->getJson('/api/transport/consignments/'.$consignment->id.'/containers')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->postJson('/api/transport/containers/'.$container->id.'/detach')
            ->assertOk();

        // §7 — the row survives. It IS the history.
        $this->assertSame(1, ConsignmentContainer::count());
        $this->getJson('/api/transport/containers/'.$container->id)
            ->assertOk()->assertJsonCount(1, 'data.history');
    }

    public function test_attaching_a_container_already_on_another_consignment_is_refused(): void
    {
        $this->signIn();
        $container = $this->container();
        $first     = $this->consignment();

        $this->postJson('/api/transport/containers/'.$container->id.'/attach', [
            'consignment_id' => $first->id,
        ])->assertCreated();

        $this->postJson('/api/transport/containers/'.$container->id.'/attach', [
            'consignment_id' => $this->consignment()->id,
        ])->assertStatus(422);

        $this->assertSame(1, ConsignmentContainer::count());
    }

    public function test_attaching_requires_a_consignment(): void
    {
        $this->signIn();

        $this->postJson('/api/transport/containers/'.$this->container()->id.'/attach', [])
            ->assertStatus(422)->assertJsonValidationErrors('consignment_id');
    }

    public function test_detaching_something_not_attached_is_refused(): void
    {
        $this->signIn();

        $this->postJson('/api/transport/containers/'.$this->container()->id.'/detach')
            ->assertStatus(422);
    }

    /* ══════════ §8 — one consignment, several containers ══════════ */

    public function test_a_consignment_can_carry_several_containers_over_http(): void
    {
        $this->signIn();
        $consignment = $this->consignment();

        foreach (['AAAA1111111', 'BBBB2222222', 'CCCC3333333'] as $n) {
            $this->postJson('/api/transport/containers/'.$this->container(self::TENANT_A, $n)->id.'/attach', [
                'consignment_id' => $consignment->id,
            ])->assertCreated();
        }

        $this->getJson('/api/transport/consignments/'.$consignment->id.'/containers')
            ->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_another_tenants_consignment_contents_are_a_404(): void
    {
        $foreign = $this->consignment(self::TENANT_B);
        $this->signIn(self::TENANT_A);

        $this->getJson('/api/transport/consignments/'.$foreign->id.'/containers')->assertNotFound();
    }

    /* ══════════ the status filter that does not exist ══════════ */

    public function test_filtering_by_status_is_refused_rather_than_ignored(): void
    {
        // Laravel drops unlisted keys, so without `prohibited` this would 200
        // and the caller would believe the list was filtered.
        $this->signIn();

        $this->getJson('/api/transport/containers?status=in_transit')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_the_list_can_filter_by_attachment_and_search(): void
    {
        $this->signIn();
        $on = $this->container(self::TENANT_A, 'AAAA1111111');
        $this->container(self::TENANT_A, 'BBBB2222222');
        $this->postJson('/api/transport/containers/'.$on->id.'/attach', [
            'consignment_id' => $this->consignment()->id,
        ])->assertCreated();

        // Asserting WHICH container comes back, not just how many: a count of 1
        // passes either way if the filter is inverted.
        $this->getJson('/api/transport/containers?attached=1')->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.container_number', 'AAAA1111111');

        $this->getJson('/api/transport/containers?attached=0')->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.container_number', 'BBBB2222222');

        // §7 "be searchable", on the normalised key, case-insensitively.
        $this->getJson('/api/transport/containers?search=bbbb')->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.container_number', 'BBBB2222222');

        // Only 1/0 are accepted. The strings "true"/"false" are refused rather
        // than coerced — PHP would read the string "false" as truthy and the
        // filter would silently return the opposite set.
        $this->getJson('/api/transport/containers?attached=true')->assertStatus(422);
        $this->getJson('/api/transport/containers?attached=false')->assertStatus(422);
    }

    /* ══════════ the gate ══════════ */

    public function test_a_client_identity_cannot_reach_any_container_route(): void
    {
        // D-46 — SCOPE_OWN narrows nothing, so the coarse role gate is the only
        // thing standing between a customer and the tenant's whole list.
        Sanctum::actingAs($this->user(self::TENANT_A, 'client'));

        $this->getJson('/api/transport/containers')->assertForbidden();
        $this->getJson('/api/transport/containers/lookup?number=ABCD1234567')->assertForbidden();
        $this->postJson('/api/transport/containers', ['container_number' => 'ABCD1234567'])->assertForbidden();
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/transport/containers')->assertUnauthorized();
    }

    public function test_there_is_no_update_or_delete_endpoint(): void
    {
        // Not an oversight: the number is the identity and §7 requires the
        // association history be maintained. This test is here so nobody adds
        // them casually.
        $this->signIn();
        $id = $this->container()->id;

        $this->putJson('/api/transport/containers/'.$id, ['container_number' => 'ZZZZ9999999'])
            ->assertStatus(405);
        $this->deleteJson('/api/transport/containers/'.$id)->assertStatus(405);
    }
}
