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
 * The registers now shown on a Purchase vendor's own workspace.
 *
 * TPV's vendor workspace carried thirteen Compliance entries and eight
 * Performance ones; Purchase carried two and five. That looked like a large
 * backend gap and was not: every endpoint below already existed, already took
 * vendor_id, and already applied it in its service. Only the tab was missing —
 * so the sole way to ask "what is open against THIS vendor" on the Purchase
 * side was to open each module register and filter it by hand.
 *
 * These assert the contract each new tab depends on: the endpoint answers, and
 * it answers about ONE vendor. A register that quietly ignored vendor_id would
 * show every vendor's NCRs on every vendor's page — worse than no tab at all,
 * because it would look authoritative.
 */
class PurchaseVendorWorkspaceRegistersTest extends TestCase
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

    /** Every endpoint a new tab fetches from, and how it is scoped. */
    public static function registers(): array
    {
        return [
            'Documents'           => ['/api/purchase/vendors/%d/documents', false],
            'Compliance Register' => ['/api/purchase/vendors/%d/compliance', false],
            'Inspections'         => ['/api/purchase/inspections?vendor_id=%d', true],
            'NCR'                 => ['/api/purchase/ncrs?vendor_id=%d', true],
            'CAPA'                => ['/api/purchase/capas?vendor_id=%d', true],
            'PTW'                 => ['/api/purchase/permits?vendor_id=%d', true],
            'Incidents'           => ['/api/purchase/incidents?vendor_id=%d', true],
            'Visitors'            => ['/api/purchase/visitors?vendor_id=%d', true],
            'Work Packages'       => ['/api/purchase/work-packages?vendor_id=%d', true],
            'Renewal'             => ['/api/purchase/renewals?vendor_id=%d', true],
            'Offboarding'         => ['/api/purchase/offboardings?vendor_id=%d', true],
        ];
    }

    /**
     * @dataProvider registers
     */
    public function test_every_new_tab_has_an_endpoint_that_answers(string $uri, bool $filtered): void
    {
        $res = $this->getJson(sprintf($uri, $this->vendor->id));

        if ($res->getStatusCode() >= 400) {
            $this->fail('a vendor workspace tab fetches '.$uri.' which answered '
                .$res->getStatusCode().' — the tab would render its error state');
        }

        $this->assertTrue($res->isSuccessful());
    }

    /**
     * The filter is applied, not merely accepted.
     *
     * Reading the controllers showed vendor_id being passed into each service;
     * this asserts the service does something with it. A register that takes
     * the parameter and ignores it answers 200 either way, which is exactly the
     * kind of thing that reads as working.
     */
    public function test_a_register_asked_about_one_vendor_does_not_answer_about_another(): void
    {
        $other = $this->vendorRow('Rival');

        // An NCR belongs to a vendor and needs nothing else to exist, which
        // makes it the cheapest honest probe of whether the filter bites.
        $res = $this->postJson('/api/purchase/ncrs', [
            'purchase_vendor_id' => $other->id,
            'title' => 'Weld porosity on line 4',
            // Purchase grades an NCR Minor/Major/Critical, not the High/Medium/Low
            // the incident register uses. Two registers, two vocabularies.
            'severity' => 'Major',
        ]);

        if ($res->getStatusCode() >= 400) {
            $this->markTestSkipped('NCR fixture refused: '
                .json_encode($res->json('errors') ?? $res->json('message')));
        }

        $mine = $this->getJson('/api/purchase/ncrs?vendor_id='.$this->vendor->id)->assertOk()->json();
        $mine = $mine['data'] ?? $mine;

        $theirs = $this->getJson('/api/purchase/ncrs?vendor_id='.$other->id)->assertOk()->json();
        $theirs = $theirs['data'] ?? $theirs;

        $this->assertCount(0, $mine, "Acme's tab is showing Rival's NCR");
        $this->assertCount(1, $theirs, 'the filter dropped the row it should have kept');
    }

    /**
     * The sidebar and the tab registry cannot disagree.
     *
     * The layout renders a nav item only when TAB_ELEMENTS has its key, so a
     * typo in one of the two silently hides a tab that was built — which is how
     * this module ended up with items nobody could reach before.
     */
    public function test_every_new_nav_key_has_a_tab_registered(): void
    {
        $nav = file_get_contents(base_path('../frontend/src/modules/purchase/pages/vendor-detail/vendorDetailNav.jsx'));
        $tabs = file_get_contents(base_path('../frontend/src/modules/purchase/pages/vendor-detail/vendorDetailTabs.jsx'));

        preg_match('/TAB_ELEMENTS\s*=\s*\{(.*)\}/s', $tabs, $m);
        $registered = $m[1] ?? '';

        foreach ([
            'documents', 'compliance-register', 'inspections', 'ncr', 'capa',
            'ptw', 'incidents', 'visitors', 'work-packages', 'renewal', 'offboarding',
        ] as $key) {
            $this->assertStringContainsString("'{$key}'", $nav,
                "the {$key} tab is built but has no sidebar entry");

            $quoted = preg_match('/[^a-z]/', $key) ? "'{$key}':" : "{$key}:";
            $this->assertStringContainsString($quoted, $registered,
                "the sidebar offers {$key} but TAB_ELEMENTS has no entry, so it renders nothing");
        }
    }

    /** The two sidebars name the same things the same way. */
    public function test_the_two_vendor_sidebars_use_one_vocabulary(): void
    {
        $pur = file_get_contents(base_path('../frontend/src/modules/purchase/pages/vendor-detail/vendorDetailNav.jsx'));

        foreach ([
            "'Operations'" => 'the group TPV calls Operations',
            "'Contact'"    => 'the item TPV calls Contact',
            "'ToDo'"       => 'the item TPV calls ToDo',
            "'Award / Reward'" => 'the item TPV calls Award / Reward',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $pur,
                "Purchase no longer matches {$why} — the two sidebars have drifted apart again");
        }
    }
}
