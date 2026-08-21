<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Format rules landed on fields that already hold unvalidated data, so the
 * behaviour that matters is: new data is clean, old data is not held hostage.
 */
class ClientValidationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        Sanctum::actingAs(User::create([
            'tenant_id' => $this->t->id, 'name' => 'Admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]));
    }

    public function test_creating_rejects_a_malformed_phone(): void
    {
        $this->postJson('/api/customers', ['company' => 'X', 'phone' => 'aaaaaaa'])
             ->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_creating_accepts_the_shapes_people_type(): void
    {
        foreach (['9820045678', '+91 98200 45678', '098200 45678'] as $ok) {
            $this->postJson('/api/customers', ['company' => 'Co '.$ok, 'phone' => $ok])
                 ->assertSuccessful();
        }
    }

    public function test_creating_rejects_a_malformed_gstin(): void
    {
        $this->postJson('/api/customers', ['company' => 'X', 'gst_number' => 'NOTAGST'])
             ->assertStatus(422)->assertJsonValidationErrors('gst_number');
    }

    /** The point of the grandfathering: legacy junk must not block an unrelated edit. */
    public function test_editing_another_field_does_not_trip_on_a_legacy_bad_gstin(): void
    {
        $c = Client::create([
            'tenant_id' => $this->t->id, 'company' => 'Legacy',
            'gst_number' => 'RUBBISH', 'active' => true,
        ]);

        $this->putJson("/api/customers/{$c->id}", [
            'company'    => 'Legacy Renamed',
            'gst_number' => 'RUBBISH',      // unchanged — must be tolerated
        ])->assertSuccessful();

        $this->assertSame('Legacy Renamed', $c->fresh()->company);
    }

    /** But actually changing it must satisfy the rule. */
    public function test_changing_a_legacy_gstin_to_something_still_invalid_is_refused(): void
    {
        $c = Client::create([
            'tenant_id' => $this->t->id, 'company' => 'Legacy',
            'gst_number' => 'RUBBISH', 'active' => true,
        ]);

        $this->putJson("/api/customers/{$c->id}", ['gst_number' => 'STILLRUBBISH'])
             ->assertStatus(422)->assertJsonValidationErrors('gst_number');
    }
}
