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
 * The Compliance Register: every vendor across the category matrix.
 *
 * The screen is a grid of category cells, each with a status select and an
 * expiry date, saved one cell at a time. Two things can go wrong invisibly:
 * the roster can answer with no categories (so the grid renders empty and the
 * header says 0/14 for ever), and a cell save can succeed without coming back
 * on the next read — which looks exactly like the dropdown "not sticking".
 */
class PurchaseComplianceRegisterTest extends TestCase
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

    public function test_the_roster_carries_the_categories_the_grid_draws(): void
    {
        Sanctum::actingAs($this->admin());

        $body = $this->getJson('/api/purchase/vendor-compliance')->assertOk()->json();

        // The page builds its 14 cells from `categories` and its dropdown from
        // `statuses`. An empty either leaves a grid with nothing in it.
        $this->assertCount(14, $body['categories'] ?? [], 'the register has no categories to draw');
        $this->assertNotEmpty($body['statuses'] ?? [], 'the status dropdown would render empty');
    }

    /**
     * `data` carries only vendors that already have a compliance record.
     *
     * The header says "Every purchase vendor", and that promise is kept on the
     * CLIENT: the page fetches the vendor list separately and merges, defaulting
     * a vendor with no records to a 0/14 row. Worth pinning, because the
     * endpoint alone does not answer the question its screen asks — anything
     * else consuming it (a report, an export) would silently omit every vendor
     * nobody has assessed yet, which is exactly the set worth chasing.
     */
    public function test_the_roster_omits_vendors_with_no_records_and_the_page_merges_them_back(): void
    {
        Sanctum::actingAs($this->admin());

        $rows = $this->getJson('/api/purchase/vendor-compliance')->assertOk()->json('data');
        $this->assertSame([], $rows ?: [], 'a vendor with no compliance records should not be in `data` yet');

        // One record, and now it appears.
        $this->postJson("/api/purchase/vendors/{$this->vendor->id}/compliance", [
            'category' => 'Legal', 'status' => 'Compliant',
            'valid_until' => now()->addYear()->toDateString(),
        ])->assertSuccessful();

        $rows = $this->getJson('/api/purchase/vendor-compliance')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('nexfore', $rows[0]['vendor'] ?? null);
    }

    public function test_setting_a_category_status_sticks(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->vendor->id;

        // The catalogue is a plain list of keys: Legal, Labour, Licences...
        $first = $this->getJson('/api/purchase/vendor-compliance')->json('categories')[0];

        $this->postJson("/api/purchase/vendors/{$v}/compliance", [
            'category' => $first,
            'status' => 'Compliant',
            'valid_until' => now()->addYear()->toDateString(),
        ])->assertSuccessful();

        // Read it back the way the screen does. A save that does not survive the
        // next load is indistinguishable, on screen, from a dropdown that will
        // not hold its value.
        // The per-vendor endpoint answers { vendor, matrix } -- not { data }.
        $rows = $this->getJson("/api/purchase/vendors/{$v}/compliance")->assertOk()->json('matrix');

        $saved = collect($rows)->firstWhere('category', $first);
        $this->assertNotNull($saved, 'the saved category is missing from the matrix');
        $this->assertSame('Compliant', $saved['stored_status'] ?? $saved['status'] ?? null);
    }

    public function test_an_expiry_in_the_past_is_reflected_in_the_status(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->vendor->id;

        $first = $this->getJson('/api/purchase/vendor-compliance')->json('categories')[0];

        $this->postJson("/api/purchase/vendors/{$v}/compliance", [
            'category' => $first,
            'status' => 'Compliant',
            'valid_until' => now()->subDay()->toDateString(),
        ])->assertSuccessful();

        $rows = $this->getJson("/api/purchase/vendors/{$v}/compliance")->assertOk()->json('matrix');
        $saved = collect($rows)->firstWhere('category', $first);

        // "Expiry drives status automatically" is what the page header promises.
        // A certificate that lapsed yesterday must not still read Compliant.
        $this->assertNotSame('Compliant', $saved['status'] ?? null,
            'an expired certificate still reports Compliant — the header promises expiry drives status');
    }
}
