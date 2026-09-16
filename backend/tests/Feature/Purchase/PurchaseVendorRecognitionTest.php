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
 * Awards and referrals on a Purchase vendor.
 *
 * The last two entries in the Purchase workspace's Performance group with
 * nothing behind them. Everything else there already had a backend and only
 * wanted a tab — risk lives in columns on purchase_vendors, the performance
 * index is computed by PurchaseVendorPerformanceService, and penalties are the
 * violations register — so these two are the whole of the remaining gap.
 *
 * Purchase-owned tables rather than a second key on TPV's. vendor_awards and
 * vendor_referrals are foreign-keyed to `vendors`, the TPV master, and hanging
 * purchase_vendor_id off them would put two unrelated vendor populations in one
 * table with half its rows null either way.
 */
class PurchaseVendorRecognitionTest extends TestCase
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

        $this->vendor = $this->vendorRow('Acme');

        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]));
    }

    private function vendorRow(string $name): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower($name).'-'.Str::random(4).'@t.local',
            'category' => 'Supplier', 'currency' => 'INR',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    /* ── Awards ─────────────────────────────────────────────────── */

    public function test_an_award_is_granted_and_listed(): void
    {
        $res = $this->postJson("/api/purchase/vendors/{$this->vendor->id}/awards", [
            'title' => 'Zero incidents, Q3',
            'category' => 'Safety',
            'description' => 'No recordable incidents across 90 days on site.',
        ]);

        if ($res->getStatusCode() >= 400) {
            $this->fail('award refused: '.$res->getStatusCode().' — '
                .json_encode($res->json('errors') ?? $res->json('message')));
        }

        $rows = $this->getJson("/api/purchase/vendors/{$this->vendor->id}/awards")->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Zero incidents, Q3', $rows[0]['title']);
        $this->assertSame('Safety', $rows[0]['category']);
    }

    public function test_an_award_is_dated_today_unless_told_otherwise(): void
    {
        $this->postJson("/api/purchase/vendors/{$this->vendor->id}/awards", [
            'title' => 'On-time delivery',
        ])->assertSuccessful();

        $rows = $this->getJson("/api/purchase/vendors/{$this->vendor->id}/awards")->json('data');

        $this->assertSame(now()->toDateString(), substr((string) $rows[0]['awarded_on'], 0, 10));
    }

    public function test_a_backdated_award_keeps_its_date(): void
    {
        // Recognition is often recorded after the fact.
        $this->postJson("/api/purchase/vendors/{$this->vendor->id}/awards", [
            'title' => 'Supplier of the year 2025',
            'awarded_on' => '2025-12-01',
        ])->assertSuccessful();

        $rows = $this->getJson("/api/purchase/vendors/{$this->vendor->id}/awards")->json('data');

        $this->assertSame('2025-12-01', substr((string) $rows[0]['awarded_on'], 0, 10));
    }

    public function test_an_award_needs_a_title(): void
    {
        $this->postJson("/api/purchase/vendors/{$this->vendor->id}/awards", ['category' => 'Safety'])
            ->assertStatus(422);
    }

    public function test_one_vendors_awards_are_not_another_vendors(): void
    {
        $other = $this->vendorRow('Rival');

        $this->postJson("/api/purchase/vendors/{$other->id}/awards", ['title' => 'Their award'])
            ->assertSuccessful();

        $mine = $this->getJson("/api/purchase/vendors/{$this->vendor->id}/awards")->json('data');

        $this->assertSame([], $mine ?: [], "Acme's page is showing Rival's award");
    }

    public function test_an_award_cannot_be_deleted_through_another_vendor(): void
    {
        $other = $this->vendorRow('Rival');

        $id = $this->postJson("/api/purchase/vendors/{$other->id}/awards", ['title' => 'Theirs'])
            ->assertSuccessful()->json('data.id');

        // Same tenant, wrong vendor in the path. Scoping by id alone would let
        // one vendor's page delete another's record.
        $this->deleteJson("/api/purchase/vendors/{$this->vendor->id}/awards/{$id}")->assertStatus(404);

        $this->assertCount(1, $this->getJson("/api/purchase/vendors/{$other->id}/awards")->json('data'));
    }

    public function test_an_award_is_removed(): void
    {
        $id = $this->postJson("/api/purchase/vendors/{$this->vendor->id}/awards", ['title' => 'Mistake'])
            ->json('data.id');

        $this->deleteJson("/api/purchase/vendors/{$this->vendor->id}/awards/{$id}")->assertOk();

        $this->assertSame([], $this->getJson("/api/purchase/vendors/{$this->vendor->id}/awards")->json('data') ?: []);
    }

    /* ── Referrals ──────────────────────────────────────────────── */

    public function test_a_referral_is_recorded_against_the_vendor_who_made_it(): void
    {
        $res = $this->postJson("/api/purchase/vendors/{$this->vendor->id}/referrals", [
            'company_name' => 'Northgate Fabrication',
            'contact_name' => 'Priya Nair',
            'contact_email' => 'priya@northgate.local',
        ]);

        if ($res->getStatusCode() >= 400) {
            $this->fail('referral refused: '.$res->getStatusCode().' — '
                .json_encode($res->json('errors') ?? $res->json('message')));
        }

        $body = $this->getJson("/api/purchase/vendors/{$this->vendor->id}/referrals")->assertOk()->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame('Northgate Fabrication', $body['data'][0]['company_name']);
        // A new introduction has not been followed up yet.
        $this->assertSame('Pending', $body['data'][0]['status']);
        $this->assertNotEmpty($body['statuses'], 'the status picker would render empty');
    }

    public function test_a_referral_moves_through_its_statuses(): void
    {
        $id = $this->postJson("/api/purchase/vendors/{$this->vendor->id}/referrals", [
            'company_name' => 'Northgate Fabrication',
        ])->json('data.id');

        $this->patchJson("/api/purchase/vendors/{$this->vendor->id}/referrals/{$id}/status", [
            'status' => 'Contacted',
        ])->assertOk();

        $rows = $this->getJson("/api/purchase/vendors/{$this->vendor->id}/referrals")->json('data');
        $this->assertSame('Contacted', $rows[0]['status']);
    }

    public function test_an_unknown_referral_status_is_refused(): void
    {
        $id = $this->postJson("/api/purchase/vendors/{$this->vendor->id}/referrals", [
            'company_name' => 'Northgate Fabrication',
        ])->json('data.id');

        $this->patchJson("/api/purchase/vendors/{$this->vendor->id}/referrals/{$id}/status", [
            'status' => 'Maybe',
        ])->assertStatus(422);
    }

    public function test_a_referral_needs_a_company(): void
    {
        // The company being introduced is the whole record; a contact name
        // without one refers to nobody.
        $this->postJson("/api/purchase/vendors/{$this->vendor->id}/referrals", [
            'contact_name' => 'Priya Nair',
        ])->assertStatus(422);
    }

    public function test_one_vendors_referrals_are_not_another_vendors(): void
    {
        $other = $this->vendorRow('Rival');

        $this->postJson("/api/purchase/vendors/{$other->id}/referrals", [
            'company_name' => 'Their introduction',
        ])->assertSuccessful();

        $mine = $this->getJson("/api/purchase/vendors/{$this->vendor->id}/referrals")->json('data');

        $this->assertSame([], $mine ?: []);
    }

    /* ── The rest of the Performance group already had backends ──── */

    public function test_the_other_performance_tabs_answer_too(): void
    {
        // Recorded so the claim is checked rather than remembered: risk, the
        // performance index and penalties were never the gap — they had
        // endpoints all along and only wanted a tab.
        foreach ([
            'performance index' => "/api/purchase/vendors/{$this->vendor->id}/vpi",
            'penalties'         => '/api/purchase/violations?vendor_id='.$this->vendor->id,
        ] as $what => $uri) {
            $res = $this->getJson($uri);
            $this->assertTrue($res->isSuccessful(),
                "the {$what} endpoint answered {$res->getStatusCode()}");
        }
    }
}
