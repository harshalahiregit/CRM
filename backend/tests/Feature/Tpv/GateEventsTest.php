<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §20 Gate Log — unified Equipment/Material (and Vehicle/Visitor/Person) entry &
 * exit events with server-side project/location/vendor filters.
 */
class GateEventsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    public function test_equipment_and_material_events_record_and_filter(): void
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/tpv/gate-events', [
            'event_kind' => 'Equipment', 'direction' => 'Entry', 'label' => 'Crane 25T',
            'vendor_id' => $v->id, 'project' => 'Refinery', 'location' => 'North Gate',
        ])->assertStatus(201)->assertJsonPath('event_kind', 'Equipment');

        $this->postJson('/api/tpv/gate-events', [
            'event_kind' => 'Material', 'direction' => 'Entry', 'label' => 'Cement',
            'quantity' => 500, 'unit' => 'bags', 'project' => 'Township',
        ])->assertStatus(201);

        // Filter by project — only the Refinery equipment event.
        $res = $this->getJson('/api/tpv/gate-events?project=Refinery')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Crane 25T', $res->json('data.0.label'));
    }

    public function test_roster_accepts_a_server_side_vendor_filter(): void
    {
        Sanctum::actingAs($this->admin());
        // Just assert the endpoint accepts the param without error.
        $this->getJson('/api/tpv/attendance?vendor_id=999')->assertOk();
    }
}
