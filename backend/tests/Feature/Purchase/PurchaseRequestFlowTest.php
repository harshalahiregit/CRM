<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Raising a purchase request, with an empty catalog.
 *
 * The New Purchase Request form leads with a catalog picker, and a fresh tenant
 * has no catalog items — so it reads "No active catalog items yet" and looks
 * like a dead end. It is not: a line is just a description, a quantity and a
 * rate, and the catalog is a convenience for pulling a standard SKU and its
 * contract rate.
 *
 * These tests assert that, because the alternative is somebody concluding the
 * module is broken on their first day.
 */
class PurchaseRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'nexfore',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'nex-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** Exactly what the form posts, with no catalog anywhere in sight. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'see safety',
            'department' => 'operation',
            'purchase_vendor_id' => $this->vendor->id,
            'priority' => 'High',
            'required_by' => now()->addYear()->toDateString(),
            'currency' => 'INR',
            'justification' => 'Site safety equipment for the new bay.',
            'items' => [
                ['description' => 'Safety helmet', 'qty' => 20, 'unit' => 'nos', 'rate' => 450, 'tax' => 18],
                ['description' => 'Hi-vis vest', 'qty' => 20, 'unit' => 'nos', 'rate' => 220, 'tax' => 18],
            ],
        ], $overrides);
    }

    public function test_a_request_saves_with_typed_items_and_no_catalog(): void
    {
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('purchase_catalog_items')->count(),
            'this test is about the empty-catalog case');

        Sanctum::actingAs($this->admin());

        $res = $this->postJson('/api/purchase/requests', $this->payload());

        if ($res->getStatusCode() >= 400) {
            $this->fail('Save Draft refused: '.$res->getStatusCode().' — '
                .json_encode($res->json('errors') ?? $res->json('message')));
        }

        $this->assertDatabaseHas('purchase_requests', ['title' => 'see safety']);
    }

    public function test_the_totals_are_computed_from_the_typed_lines(): void
    {
        Sanctum::actingAs($this->admin());

        $id = $this->postJson('/api/purchase/requests', $this->payload())->json('id');
        $body = $this->getJson("/api/purchase/requests/{$id}")->assertOk()->json();
        $row = $body['data'] ?? $body;

        // 20 × 450 + 20 × 220 = 13,400 before tax. A subtotal stuck at zero is
        // the symptom that says "the lines did not save" even when they did.
        $subtotal = (float) ($row['subtotal'] ?? $row['sub_total'] ?? 0);
        $this->assertSame(13400.0, $subtotal, 'the request subtotal did not follow the typed lines');
    }

    public function test_a_request_can_be_submitted_for_approval(): void
    {
        Sanctum::actingAs($this->admin());

        $id = $this->postJson('/api/purchase/requests', $this->payload())->json('id');

        // The second of the two buttons on that modal. A draft that cannot be
        // submitted is a form with a dead end at the end of it.
        $res = $this->postJson("/api/purchase/requests/{$id}/submit");

        if ($res->getStatusCode() >= 400) {
            $this->fail('Submit for Approval refused: '.$res->getStatusCode().' — '
                .json_encode($res->json('errors') ?? $res->json('message')));
        }

        $fresh = $this->getJson("/api/purchase/requests/{$id}")->assertOk()->json();
        $fresh = $fresh['data'] ?? $fresh;

        $this->assertNotSame('Draft', $fresh['status'] ?? null,
            'the request is still a draft after being submitted');
    }

    public function test_a_request_with_no_lines_is_refused_clearly(): void
    {
        Sanctum::actingAs($this->admin());

        $res = $this->postJson('/api/purchase/requests', $this->payload(['items' => []]));

        // A request for nothing is not a request. What matters is that it says
        // so — the form's own empty state already looks like a dead end, and a
        // silent failure on top of that is how somebody gives up.
        $this->assertGreaterThanOrEqual(400, $res->getStatusCode(),
            'a request with no line items was accepted');
    }
}
