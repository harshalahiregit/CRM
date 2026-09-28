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
 * Clicking Edit on a Purchase vendor must arrive with the vendor in it.
 *
 * Reported from the running system: "in purchase vendors on admin side when I
 * click edit on any vendor then no data is there".
 *
 * The edit modal seeds itself from GET /purchase/vendors/{id} — not from the
 * list row, because the list is a summary. So a blank form has exactly three
 * possible causes: the endpoint refuses the admin, it answers with a different
 * shape than the client unwraps, or it drops the fields on the way out. This
 * pins all three at the HTTP boundary, which is the one place tinker cannot
 * check: a model that reads fine through the service can still serialise to
 * nothing through $hidden or a resource.
 */
class PurchaseVendorEditPayloadTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** A vendor with every field the edit form draws actually filled in. */
    private function vendor(): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'company_name' => 'Southgate Industrial Pvt Ltd',
            'legal_name' => 'Southgate Industrial Private Limited',
            'vendor_type' => 'Permanent',
            'email' => 'accounts@southgate.local',
            'phone' => '+91 22 4000 1234',
            'website' => 'https://southgate.local',
            'category' => 'Mechanical',
            'registration_number' => 'REG-99881',
            'gst_number' => '27AABCS1429B1Z1',
            'pan_number' => 'AABCS1429B',
            'address' => '14 Dockyard Road',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'country' => 'India',
            'pincode' => '400010',
            'currency' => 'INR',
            'language' => 'System Default',
            'payment_terms' => 'Net 45',
            'return_policy' => 'Returns accepted within 15 days.',
            'notes' => 'Preferred supplier for dockside fabrication.',
            'status' => 'Active',
            'portal_status' => 'active',
        ]);
    }

    /** Every field the form draws has to survive the round trip. */
    public function test_the_edit_endpoint_returns_the_whole_vendor(): void
    {
        $vendor = $this->vendor();

        Sanctum::actingAs($this->admin());
        $body = $this->getJson("/api/purchase/vendors/{$vendor->id}")->assertOk()->json();

        foreach ([
            'company_name' => 'Southgate Industrial Pvt Ltd',
            'legal_name' => 'Southgate Industrial Private Limited',
            'vendor_type' => 'Permanent',
            'email' => 'accounts@southgate.local',
            'phone' => '+91 22 4000 1234',
            'website' => 'https://southgate.local',
            'category' => 'Mechanical',
            'registration_number' => 'REG-99881',
            'gst_number' => '27AABCS1429B1Z1',
            'pan_number' => 'AABCS1429B',
            'address' => '14 Dockyard Road',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'country' => 'India',
            'pincode' => '400010',
            'currency' => 'INR',
            'payment_terms' => 'Net 45',
            'return_policy' => 'Returns accepted within 15 days.',
            'notes' => 'Preferred supplier for dockside fabrication.',
        ] as $field => $expected) {
            $this->assertArrayHasKey($field, $body, "the edit payload carries {$field}");
            $this->assertSame($expected, $body[$field],
                "{$field} came back changed or empty — the edit form draws this field");
        }
    }

    /**
     * The client does `full?.data ?? full`, so a payload that nests the vendor
     * under anything other than `data` seeds the form with the wrapper and every
     * field reads blank. This pins the shape, not just the contents.
     */
    public function test_the_vendor_is_at_the_top_level_of_the_response(): void
    {
        $vendor = $this->vendor();

        Sanctum::actingAs($this->admin());
        $body = $this->getJson("/api/purchase/vendors/{$vendor->id}")->assertOk()->json();

        $this->assertSame($vendor->id, $body['id'] ?? null,
            'the vendor is the response, not nested inside it');
        $this->assertArrayNotHasKey('vendor', $body, 'no wrapper key the client would have to unwrap');
    }

    /** The list is a summary, but it still has to carry what the row renders. */
    public function test_the_list_row_carries_enough_to_fall_back_on(): void
    {
        $this->vendor();

        Sanctum::actingAs($this->admin());
        $rows = $this->getJson('/api/purchase/vendors')->assertOk()->json();
        $row = $rows['data'][0] ?? $rows[0] ?? null;

        // openEdit falls back to the row when the fetch fails, so an empty row
        // would turn a flaky request into an empty form.
        $this->assertNotNull($row);
        $this->assertSame('Southgate Industrial Pvt Ltd', $row['company_name'] ?? null);
    }
}
