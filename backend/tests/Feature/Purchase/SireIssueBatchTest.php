<?php

namespace Tests\Feature\Purchase;

use App\Models\Customer\Client;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The server-side half of the SIRE issue batch of 19 Sep 2026.
 *
 * Two of those reports asked for behaviour that cannot live in a form, because
 * the vendor portal reaches the same endpoints the admin screens do:
 *
 *   SIR-000014  workforce must not be added against an unapproved vendor
 *   SIR-000012  a customer linked to a vendor must be correctable in place
 *
 * A disabled button is a courtesy. These are the rules.
 */
class SireIssueBatchTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /**
     * `category` matters: it decides whether the vendor's onboarding puts the
     * Workforce step before or after Approval. 'Consumables' is a service
     * category — no workforce step — which is the strict case.
     */
    private function vendor(string $status = 'Active', string $name = 'Southgate', string $category = 'Consumables'): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower($name).'-'.Str::random(4).'@t.local',
            'status' => $status, 'portal_status' => 'active', 'category' => $category,
        ]);
    }

    /** Give a vendor an onboarding in a particular state. */
    private function onboarding(PurchaseVendor $v, string $status, int $step = 1): void
    {
        \App\Models\Purchase\PurchaseOnboarding::create([
            'tenant_id' => self::TENANT,
            'purchase_vendor_id' => $v->id,
            'status' => $status,
            'current_step' => $step,
        ]);
    }

    private function workerPayload(PurchaseVendor $v): array
    {
        return [
            'purchase_vendor_id' => $v->id,
            'full_name' => 'Ravi Kumar',
            'dob' => now()->subYears(30)->toDateString(),
            'gender' => 'Male',
            'trade' => 'Fitter',
        ];
    }

    /* ── SIR-000014 — no workforce before approval ───────────────────── */

    /**
     * The case actually reported, and the one a first pass got wrong.
     *
     * The vendor's status column says Active while its ONBOARDING is still
     * In_Progress at step 1. Gating on status let this straight through: the
     * vendor stayed selectable in Add Worker and the worker saved. The gate is
     * the onboarding.
     */
    public function test_an_active_vendor_whose_onboarding_is_unfinished_is_refused(): void
    {
        $vendor = $this->vendor('Active');
        $this->onboarding($vendor, 'In_Progress', 1);

        Sanctum::actingAs($this->admin());
        $res = $this->postJson('/api/purchase/workforce/workers', $this->workerPayload($vendor))
            ->assertStatus(422);

        $this->assertStringContainsString('onboarding', strtolower((string) $res->json('message')));
        $this->assertDatabaseMissing('purchase_workers', ['purchase_vendor_id' => $vendor->id]);
    }

    /** And the picker must not offer it either — same flag the form reads. */
    public function test_such_a_vendor_is_not_offered_in_the_picker(): void
    {
        $unfinished = $this->vendor('Active', 'Unfinished');
        $this->onboarding($unfinished, 'In_Progress', 1);

        $done = $this->vendor('Active', 'Done');
        $this->onboarding($done, 'Approved', 6);

        Sanctum::actingAs($this->admin());
        $rows = $this->getJson('/api/purchase/vendors?per_page=200')->assertOk()->json();
        $list = $rows['data'] ?? $rows;

        $byId = collect($list)->keyBy('id');
        $this->assertFalse($byId[$unfinished->id]['can_register_workers'],
            'a vendor mid-onboarding must not be offered');
        $this->assertTrue($byId[$done->id]['can_register_workers'],
            'an approved onboarding must still be offered');
    }

    /** An approved onboarding is the thing that opens it. */
    public function test_an_approved_onboarding_admits_a_workforce(): void
    {
        $vendor = $this->vendor('Active');
        $this->onboarding($vendor, 'Approved', 6);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/purchase/workforce/workers', $this->workerPayload($vendor))
            ->assertSuccessful();

        $this->assertDatabaseHas('purchase_workers', ['purchase_vendor_id' => $vendor->id]);
    }

    /**
     * The state that prompted the report: a vendor still in onboarding.
     *
     * Registration used to be allowed here, with a banner explaining the people
     * being entered could never be badged.
     */
    public function test_a_worker_cannot_be_registered_against_an_unapproved_vendor(): void
    {
        $vendor = $this->vendor('Pending_Approval');

        Sanctum::actingAs($this->admin());
        $res = $this->postJson('/api/purchase/workforce/workers', $this->workerPayload($vendor))
            ->assertStatus(422);

        // The refusal has to name the remedy, or the reader is left guessing
        // which of a dozen rules they tripped.
        $this->assertStringContainsString('onboarding', strtolower((string) $res->json('message')));

        $this->assertDatabaseMissing('purchase_workers', ['purchase_vendor_id' => $vendor->id]);
    }

    /** Every non-Active state, not just the one that happened to be reported. */
    public function test_no_unapproved_state_admits_a_service_vendors_workforce(): void
    {
        Sanctum::actingAs($this->admin());

        foreach (['Draft', 'Pending_Approval', 'Inactive', 'On_Hold', 'Rejected', 'Blacklisted'] as $state) {
            $vendor = $this->vendor($state, 'V'.$state);

            $res = $this->postJson('/api/purchase/workforce/workers', $this->workerPayload($vendor));
            $this->assertSame(422, $res->getStatusCode(),
                "a {$state} vendor must not accept a workforce");
        }

        $this->assertSame(0, \App\Models\Purchase\PurchaseWorker::count());
    }

    /**
     * No category is exempt, including the one that looks like it should be.
     *
     * An earlier pass let workforce categories (security, housekeeping,
     * manpower) register during their own onboarding, reading their displayed
     * flow as Profile -> Documents -> WORKFORCE -> Approvals. That exception was
     * wrong: PurchaseOnboardingService::submit asks for the company profile and
     * the documents and nothing else, so no vendor has ever needed to register a
     * worker in order to be approved. The carve-out only widened the hole.
     */
    public function test_even_a_workforce_category_waits_for_approval(): void
    {
        $vendor = $this->vendor('Pending_Approval', 'Guarding', 'Security Services');
        $this->onboarding($vendor, 'In_Progress', 3);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/purchase/workforce/workers', $this->workerPayload($vendor))
            ->assertStatus(422);

        $this->assertDatabaseMissing('purchase_workers', ['purchase_vendor_id' => $vendor->id]);
    }

    /** A vendor with no onboarding record at all is the least onboarded of all. */
    public function test_a_vendor_with_no_onboarding_cannot_register_workers(): void
    {
        $vendor = $this->vendor('Active');   // Active, but no wizard ever started

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/purchase/workforce/workers', $this->workerPayload($vendor))
            ->assertStatus(422);

        $this->assertDatabaseMissing('purchase_workers', ['purchase_vendor_id' => $vendor->id]);
    }

    /**
     * But only while the onboarding is actually live.
     *
     * On hold or rejected, the workforce step is not "in progress" — it is
     * stopped, and workers added then are precisely the orphans reported.
     */
    public function test_a_stopped_workforce_vendor_is_still_refused(): void
    {
        Sanctum::actingAs($this->admin());

        foreach (['On_Hold', 'Rejected', 'Blacklisted', 'Inactive'] as $state) {
            $vendor = $this->vendor($state, 'Guards'.$state, 'Housekeeping');
            $this->onboarding($vendor, 'In_Progress', 3);

            $res = $this->postJson('/api/purchase/workforce/workers', $this->workerPayload($vendor));
            $this->assertSame(422, $res->getStatusCode(),
                "a {$state} workforce vendor must not accept a workforce");
        }

        $this->assertSame(0, \App\Models\Purchase\PurchaseWorker::count());
    }

    /** And the rule must not have swallowed the normal case. */
    public function test_an_approved_vendor_still_takes_a_workforce(): void
    {
        $vendor = $this->vendor('Active');
        $this->onboarding($vendor, 'Approved', 6);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/purchase/workforce/workers', $this->workerPayload($vendor))
            ->assertSuccessful();

        $this->assertDatabaseHas('purchase_workers', [
            'purchase_vendor_id' => $vendor->id, 'full_name' => 'Ravi Kumar',
        ]);
    }

    /**
     * The bulk route is the same rule, and the interesting one.
     *
     * Forty rows accepted against a vendor nobody approved is exactly the
     * "confusion" the report names, and it is the path where it happens at
     * scale.
     */
    public function test_the_bulk_import_is_refused_for_an_unapproved_vendor(): void
    {
        $vendor = $this->vendor('On_Hold');

        Sanctum::actingAs($this->admin());

        $csv = "Full Name,Gender,DOB,Mobile,Blood Group,Designation,Skill Category,ID Number,Photo Filename\n"
            ."Ravi Kumar,Male,1994-03-02,9876543210,B+,Fitter,Skilled,123456789012,\n";

        $this->post('/api/purchase/workforce/workers/upload', [
            'vendor_id' => $vendor->id,
            'worker_file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('w.csv', $csv),
        ])->assertStatus(422);

        $this->assertSame(0, \App\Models\Purchase\PurchaseWorker::count(),
            'not one row may land when the vendor is not approved');
    }

    /* ── SIR-000012 — a linked customer can be corrected ─────────────── */

    public function test_a_linked_customer_can_be_edited_in_place(): void
    {
        $vendor = $this->vendor('Active');
        $client = Client::create([
            'tenant_id' => self::TENANT, 'company' => 'Acme Pvt Ltd',
            'purchase_vendor_id' => $vendor->id, 'active' => true, 'phone' => '111',
        ]);

        Sanctum::actingAs($this->admin());
        $this->putJson("/api/purchase/vendors/{$vendor->id}/customers/{$client->id}", [
            'company' => 'Acme Private Limited',
            'phone' => '9820098200',
            'city' => 'Pune',
        ])->assertOk();

        $this->assertDatabaseHas('clients', [
            'id' => $client->id, 'company' => 'Acme Private Limited',
            'phone' => '9820098200', 'city' => 'Pune',
        ]);
    }

    /**
     * It is a correction route, not a door into the Customer module.
     *
     * A client that belongs to nobody — or to a different vendor — must be
     * unreachable through this vendor's URL, otherwise "edit the customer on my
     * vendor" quietly becomes "edit any customer in the tenant".
     */
    public function test_a_customer_on_another_vendor_cannot_be_edited_through_this_one(): void
    {
        $mine = $this->vendor('Active', 'Mine');
        $theirs = $this->vendor('Active', 'Theirs');

        $client = Client::create([
            'tenant_id' => self::TENANT, 'company' => 'Not Mine',
            'purchase_vendor_id' => $theirs->id, 'active' => true,
        ]);

        Sanctum::actingAs($this->admin());
        $this->putJson("/api/purchase/vendors/{$mine->id}/customers/{$client->id}", [
            'company' => 'Hijacked',
        ])->assertStatus(404);

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'company' => 'Not Mine']);
    }

    /** An unlinked client is equally out of reach. */
    public function test_an_unlinked_customer_cannot_be_edited_through_a_vendor(): void
    {
        $vendor = $this->vendor('Active');
        $client = Client::create([
            'tenant_id' => self::TENANT, 'company' => 'Standalone', 'active' => true,
        ]);

        Sanctum::actingAs($this->admin());
        $this->putJson("/api/purchase/vendors/{$vendor->id}/customers/{$client->id}", [
            'company' => 'Hijacked',
        ])->assertStatus(404);

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'company' => 'Standalone']);
    }

    /**
     * The route must not have eaten /customers/search.
     *
     * Both sit directly under /customers; a wildcard that is not number-bound
     * matches the literal word "search" and the picker dies with a 404 that
     * looks like a missing customer.
     */
    public function test_the_edit_route_does_not_shadow_customer_search(): void
    {
        $vendor = $this->vendor('Active');

        Sanctum::actingAs($this->admin());
        $this->getJson("/api/purchase/vendors/{$vendor->id}/customers/search?q=ac")->assertOk();
    }
}
