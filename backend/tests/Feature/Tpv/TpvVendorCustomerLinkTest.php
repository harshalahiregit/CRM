<?php

namespace Tests\Feature\Tpv;

use App\Models\Customer\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TPV "Add Customer" — search an existing (registered) customer and link it to a
 * vendor (clients.vendor_id), instead of always creating a new one.
 */
class TpvVendorCustomerLinkTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private const OTHER  = 2;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([self::TENANT => 't1', self::OTHER => 't2'] as $id => $slug) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => strtoupper($slug), 'slug' => $slug,
                'subdomain' => $slug, 'status' => 'active',
            ])->save();
        }
    }

    private function user(string $role, int $tenantId = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name, int $tenantId = self::TENANT): Vendor
    {
        return Vendor::create([
            'tenant_id' => $tenantId, 'company_name' => $name,
            'email' => strtolower(Str::slug($name)).'@test.local', 'status' => 'Active',
        ]);
    }

    private function client(string $company, array $attrs = [], int $tenantId = self::TENANT): Client
    {
        return Client::create(array_merge(['tenant_id' => $tenantId, 'company' => $company, 'active' => 1], $attrs));
    }

    public function test_search_returns_only_linkable_tenant_customers(): void
    {
        $v     = $this->vendor('AlphaCo');
        $other = $this->vendor('BetaCo');

        $free    = $this->client('Acme Industries', ['phone' => '999888']);
        $onOther = $this->client('Taken Corp', ['vendor_id' => $other->id]);
        $foreign = $this->client('Acme Overseas', [], self::OTHER);

        Sanctum::actingAs($this->user('admin'));
        $rows = $this->getJson("/api/tpv/vendors/{$v->id}/customers/search?q=Acme")->assertOk()->json();

        $ids = collect($rows)->pluck('id');
        $this->assertTrue($ids->contains($free->id), 'an unlinked matching customer is returned');
        $this->assertFalse($ids->contains($onOther->id), 'a customer linked to another vendor is excluded');
        $this->assertFalse($ids->contains($foreign->id), "another tenant's customer never appears");
    }

    public function test_search_matches_phone_and_gst(): void
    {
        $v = $this->vendor('AlphaCo');
        $byPhone = $this->client('Phone Co', ['phone' => '1234567']);
        $byGst   = $this->client('Gst Co', ['gst_number' => '27ABCDE1234F1Z5']);

        Sanctum::actingAs($this->user('admin'));
        $this->assertSame([$byPhone->id], collect($this->getJson("/api/tpv/vendors/{$v->id}/customers/search?q=234567")->json())->pluck('id')->all());
        $this->assertSame([$byGst->id], collect($this->getJson("/api/tpv/vendors/{$v->id}/customers/search?q=27ABCDE")->json())->pluck('id')->all());
    }

    public function test_link_attaches_an_existing_customer_and_is_idempotent(): void
    {
        $v = $this->vendor('AlphaCo');
        $c = $this->client('Acme Industries');

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/tpv/vendors/{$v->id}/customers/link", ['client_id' => $c->id])->assertOk();
        $this->assertSame($v->id, $c->fresh()->vendor_id);

        // Idempotent — linking again is fine and does not error.
        $this->postJson("/api/tpv/vendors/{$v->id}/customers/link", ['client_id' => $c->id])->assertOk();
        $this->assertSame($v->id, $c->fresh()->vendor_id);

        // And it now shows in the vendor's customers list.
        $list = $this->getJson("/api/tpv/vendors/{$v->id}/customers")->assertOk()->json();
        $this->assertContains($c->id, collect($list)->pluck('id')->all());
    }

    public function test_link_refuses_to_steal_another_vendors_customer(): void
    {
        $mine   = $this->vendor('MineCo');
        $theirs = $this->vendor('TheirCo');
        $c      = $this->client('Contested Corp', ['vendor_id' => $theirs->id]);

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/tpv/vendors/{$mine->id}/customers/link", ['client_id' => $c->id])->assertStatus(422);
        $this->assertSame($theirs->id, $c->fresh()->vendor_id, 'the customer stays with its original vendor');
    }

    public function test_link_cannot_reach_a_foreign_tenant_customer(): void
    {
        $v       = $this->vendor('MineCo');
        $foreign = $this->client('Overseas Ltd', [], self::OTHER);

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/tpv/vendors/{$v->id}/customers/link", ['client_id' => $foreign->id])->assertStatus(404);
        $this->assertNull($foreign->fresh()->vendor_id);
    }

    public function test_portal_roles_cannot_search_or_link(): void
    {
        $v = $this->vendor('DenyCo');
        $c = $this->client('Secret Client');

        foreach (['third_party_vendor', 'vendor', 'client'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson("/api/tpv/vendors/{$v->id}/customers/search?q=Secret")->assertForbidden();
            $this->postJson("/api/tpv/vendors/{$v->id}/customers/link", ['client_id' => $c->id])->assertForbidden();
        }
    }
}
