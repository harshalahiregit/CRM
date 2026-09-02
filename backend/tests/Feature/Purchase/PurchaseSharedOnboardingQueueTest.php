<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Purchase\PurchaseOnboardingStatus as OS;
use App\Support\Purchase\PurchaseVendorStatus as VS;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The API contract the SHARED onboarding queue depends on.
 *
 * /app/purchase/onboarding and /app/tpv/onboarding now render the SAME
 * component, which reads its data source and its foreign-key name from
 * useVendorModule(). That only holds while both modules answer with the same
 * shape, so this pins the Purchase half of the contract:
 *
 *   - the queue's five KPI keys exist and are named identically to TPV's
 *     (a renamed key does not error, it silently reads zero);
 *   - list rows carry the vendor relation, current_step and status the cards
 *     render;
 *   - create accepts `purchase_vendor_id` — NOT the `vendor_id` TPV takes,
 *     which is why the key is part of the module config rather than hardcoded
 *     in the form;
 *   - the §10 checklist and the work-start letter are reachable over HTTP.
 *     Both had working services and routes but no client method at all, so
 *     nothing in the product could call them.
 */
class PurchaseSharedOnboardingQueueTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name, array $extra = []): PurchaseVendor
    {
        return PurchaseVendor::create(array_merge([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower($name).'@test.local',
            'status' => VS::DRAFT, 'portal_status' => 'active',
        ], $extra));
    }

    /**
     * The five keys the queue's KPI row reads. Named exactly as TPV names them
     * — the shared component indexes stats by key, so a rename here shows five
     * zeroes rather than an error.
     */
    public function test_stats_expose_the_same_five_keys_tpv_does(): void
    {
        Sanctum::actingAs($this->admin());

        $mk = function (string $name, string $status) {
            PurchaseOnboarding::create([
                'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->vendor($name)->id,
                'current_step' => 1, 'status' => $status,
            ]);
        };
        $mk('AlphaCo', OS::IN_PROGRESS);
        $mk('BetaCo', OS::SUBMITTED);
        $mk('GammaCo', OS::APPROVED);
        $mk('DeltaCo', OS::REJECTED);

        $body = $this->getJson('/api/purchase/onboarding/stats')
            ->assertOk()->json();

        foreach (['total', 'in_progress', 'awaiting', 'approved', 'rejected'] as $key) {
            $this->assertArrayHasKey($key, $body, "stats is missing '{$key}'");
        }

        $this->assertSame(4, $body['total']);
        $this->assertSame(1, $body['in_progress']);
        $this->assertSame(1, $body['approved']);
        $this->assertSame(1, $body['rejected']);
        // 'awaiting' counts what is waiting on a decision, not literally
        // Submitted — asserting it is non-zero keeps the queue's amber card
        // honest without pinning the scope of awaitingApproval().
        $this->assertGreaterThanOrEqual(1, $body['awaiting']);
    }

    /** Rows must carry what the cards render, or every card reads "Vendor —". */
    public function test_list_rows_carry_vendor_step_and_status(): void
    {
        Sanctum::actingAs($this->admin());

        $vendor = $this->vendor('RowCo');
        PurchaseOnboarding::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'current_step' => 3, 'status' => OS::IN_PROGRESS,
        ]);

        $rows = $this->getJson('/api/purchase/onboarding')->assertOk()->json();
        $rows = $rows['data'] ?? $rows;

        $this->assertNotEmpty($rows, 'the onboarding list came back empty');
        $row = $rows[0];

        $this->assertSame(3, $row['current_step']);
        $this->assertSame(OS::IN_PROGRESS, $row['status']);
        $this->assertSame('RowCo', $row['vendor']['company_name'] ?? null);
        // The queue shows the code beside the name. Purchase stores it under a
        // different column than TPV, which is why the component reads it
        // through cfg.codeOf() rather than by name.
        $this->assertSame(
            $vendor->purchase_vendor_code,
            $row['vendor']['purchase_vendor_code'] ?? null
        );
    }

    /**
     * Purchase takes `purchase_vendor_id`; TPV takes `vendor_id`. The shared
     * create form would 422 against one of them if the name were hardcoded.
     */
    public function test_create_requires_the_purchase_foreign_key_name(): void
    {
        Sanctum::actingAs($this->admin());
        $vendor = $this->vendor('CreateCo');

        $this->postJson('/api/purchase/onboarding', ['vendor_id' => $vendor->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('purchase_vendor_id');

        $this->postJson('/api/purchase/onboarding', ['purchase_vendor_id' => $vendor->id])
            ->assertCreated();
    }

    /**
     * The §10 checklist over HTTP. The rule engine draws a longer list for a
     * higher-risk vendor — if it did not, the config would not be reaching the
     * vendor's dimensions at all and every vendor would get the general set.
     */
    public function test_checklist_is_reachable_and_risk_scaled(): void
    {
        Sanctum::actingAs($this->admin());

        $high = PurchaseOnboarding::create([
            'tenant_id' => self::TENANT, 'current_step' => 1, 'status' => OS::IN_PROGRESS,
            'purchase_vendor_id' => $this->vendor('HighCo', ['risk_level' => 'High'])->id,
        ]);
        $low = PurchaseOnboarding::create([
            'tenant_id' => self::TENANT, 'current_step' => 1, 'status' => OS::IN_PROGRESS,
            'purchase_vendor_id' => $this->vendor('LowCo', ['risk_level' => 'Low'])->id,
        ]);

        $highBody = $this->getJson("/api/purchase/onboarding/{$high->id}/checklist")->assertOk()->json();
        $lowBody  = $this->getJson("/api/purchase/onboarding/{$low->id}/checklist")->assertOk()->json();

        $this->assertNotEmpty($highBody['items']);
        $this->assertGreaterThan(
            count($lowBody['items']),
            count($highBody['items']),
            'a High-risk vendor should draw more checklist items than a Low-risk one'
        );

        // Ticking one item must not clear the others — the service merges.
        $first  = $highBody['items'][0]['item'];
        $second = $highBody['items'][1]['item'];

        $this->postJson("/api/purchase/onboarding/{$high->id}/checklist", ['state' => [$first => true]])->assertOk();
        $after = $this->postJson("/api/purchase/onboarding/{$high->id}/checklist", ['state' => [$second => true]])
            ->assertOk()->json();

        $done = collect($after['items'])->filter(fn ($i) => $i['done'])->pluck('item')->all();
        $this->assertContains($first, $done, 'the first tick was cleared by the second write');
        $this->assertContains($second, $done);
        $this->assertNotContains($first, $after['missing']);
    }

    /**
     * The letter is issued on approval. Before this it had a column and no
     * writer, so an approved vendor had no document saying they could start.
     */
    public function test_work_start_letter_is_served_for_an_approved_onboarding(): void
    {
        Sanctum::actingAs($this->admin());

        $ob = PurchaseOnboarding::create([
            'tenant_id' => self::TENANT, 'current_step' => 6, 'status' => OS::APPROVED,
            'purchase_vendor_id' => $this->vendor('LetterCo')->id,
        ]);

        $res = $this->get("/api/purchase/onboarding/{$ob->id}/work-start-letter");
        $res->assertOk();

        $this->assertNotEmpty($res->streamedContent(), 'the letter came back empty');
        $this->assertNotNull($ob->fresh()->work_start_letter_path, 'the path was not recorded');
    }

    /** A vendor still in progress has no letter — and must not 500 asking. */
    public function test_work_start_letter_is_not_available_before_approval(): void
    {
        Sanctum::actingAs($this->admin());

        $ob = PurchaseOnboarding::create([
            'tenant_id' => self::TENANT, 'current_step' => 2, 'status' => OS::IN_PROGRESS,
            'purchase_vendor_id' => $this->vendor('EarlyCo')->id,
        ]);

        $this->get("/api/purchase/onboarding/{$ob->id}/work-start-letter")->assertNotFound();
    }
}
