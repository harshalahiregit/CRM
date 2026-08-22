<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A customer portal token must never satisfy a staff role gate.
 *
 * EnsureUserHasRole compared $user->role as a string. Several authenticatable
 * models carry a `role` column, and client_contacts.role is a FREE-TEXT field
 * on the customer contact form — it holds things like "Procurement" or
 * "Finance". A contact whose role was typed as "admin" therefore satisfied
 * role:admin,staff and reached the whole staff API with a portal token: the
 * tenant's full customer list, 360 overviews, notes, invoices, vendors.
 *
 * Nothing stopped it but the absence of anybody having typed that word.
 */
class PortalTokenCannotReachStaffApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->client = Client::create([
            'tenant_id' => $this->tenant->id, 'company' => 'Widget Ltd', 'active' => true,
        ]);
    }

    private function contactWithRole(?string $role): ClientContact
    {
        return ClientContact::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'first_name' => 'Mal', 'last_name' => 'Actor', 'email' => 'mal@widget.test',
            'active' => true, 'portal_status' => 'active', 'role' => $role,
            'password' => Hash::make('secret123'), 'permissions' => ['invoice'],
        ]);
    }

    /** @return array<int,string> staff endpoints behind role:admin,staff */
    private function staffEndpoints(): array
    {
        return [
            '/api/customers',
            "/api/customers/{$this->client->id}",
            "/api/customers/{$this->client->id}/overview",
            "/api/customers/{$this->client->id}/notes",
            "/api/customers/{$this->client->id}/timeline",
        ];
    }

    public function test_a_contact_whose_role_reads_admin_is_still_refused(): void
    {
        Sanctum::actingAs($this->contactWithRole('admin'), ['*']);

        foreach ($this->staffEndpoints() as $url) {
            $this->getJson($url)->assertStatus(403, "portal token reached {$url}");
        }
    }

    public function test_the_same_holds_for_staff_and_for_odd_casing(): void
    {
        foreach (['staff', 'Admin', 'ADMIN', ' admin '] as $role) {
            $contact = ClientContact::create([
                'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
                'first_name' => 'X', 'email' => 'x'.md5($role).'@widget.test',
                'active' => true, 'portal_status' => 'active', 'role' => $role,
                'password' => Hash::make('secret123'), 'permissions' => [],
            ]);

            Sanctum::actingAs($contact, ['*']);
            $this->getJson('/api/customers')->assertStatus(403, "role '{$role}' got through");
        }
    }

    public function test_a_real_staff_user_is_unaffected(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]));

        $this->getJson('/api/customers')->assertOk();
    }

    public function test_the_contact_form_refuses_a_reserved_role_word(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'b@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]));

        $this->postJson("/api/customers/{$this->client->id}/contacts", [
            'first_name' => 'Mal', 'email' => 'mal2@widget.test', 'role' => 'admin',
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        // A genuine §11 role is still accepted.
        $this->postJson("/api/customers/{$this->client->id}/contacts", [
            'first_name' => 'Real', 'email' => 'real@widget.test', 'role' => 'Procurement',
        ])->assertCreated();
    }
}
