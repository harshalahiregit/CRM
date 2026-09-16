<?php

namespace Tests\Feature\Purchase;

use App\Models\Inventory\Product;
use App\Models\Purchase\PurchasePpeRequirement;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerPpeIssue;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchasePpeService;
use App\Services\Purchase\PurchaseWorkforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase's PPE requirement matrix — role needs item, by name.
 *
 * Purchase had no such table, so its PPE rule was the weakest its data
 * supported: holding ANY one item counted as equipped. A worker with one pair of
 * gloves passed a check that on TPV demanded a helmet and boots BY NAME. Both
 * the badge and the site gate read that rule, so it was a safety difference
 * between the two engines rather than a cosmetic one.
 *
 * Two properties matter most here and are easy to get wrong in opposite
 * directions:
 *
 *   · with rules configured, the gate must name what is MISSING;
 *   · with NO rules configured, nothing may tighten — a tenant who has not
 *     filled the matrix in must not suddenly be unable to issue any badge.
 *
 * Only `mandatory` gates. Optional and conditional PPE is advisory and must
 * never block a badge, or the matrix becomes something people route around.
 */
class PurchasePpeRequirementTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseVendor $vendor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate Industrial',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => Str::random(5).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);

        $this->admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Priya Nair', 'role' => 'admin',
            'email' => 'a-'.Str::random(5).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function worker(string $designation = 'Fitter'): PurchaseWorker
    {
        return PurchaseWorker::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->vendor->id,
            'full_name' => 'Rita Bose', 'designation' => $designation,
            'skill_category' => 'Skilled', 'status' => 'Pending', 'current_step' => 4,
        ]);
    }

    /** A worker taken all the way to badge-ready, short only of PPE. */
    private function readyWorker(string $designation = 'Fitter'): PurchaseWorker
    {
        $wf = app(PurchaseWorkforceService::class);
        $w = $this->worker($designation);

        $wf->addDocument($w, 'id_proof', \Illuminate\Http\UploadedFile::fake()->create('id.pdf', 5));
        $wf->saveMedical($w, ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()]);
        $w->fresh()->latestMedical?->forceFill([
            'qc_status' => \App\Support\Medical\MedicalQcStatus::APPROVED, 'qc_at' => now(),
        ])->save();
        $wf->saveTraining($w, ['title' => 'Safety', 'status' => 'Completed']);
        $wf->saveInduction($w, ['status' => 'Completed']);
        $w->fresh()->forceFill(['current_step' => 4])->save();

        return $w->fresh();
    }

    private function product(string $name): Product
    {
        return Product::create([
            'tenant_id' => self::TENANT, 'name' => $name,
            'sku' => strtoupper(Str::random(8)), 'status' => 'Active',
        ]);
    }

    private function require(Product $p, string $scopeType = 'designation', ?string $scopeValue = 'Fitter', string $class = 'mandatory'): PurchasePpeRequirement
    {
        return PurchasePpeRequirement::create([
            'tenant_id' => self::TENANT,
            'scope_type' => $scopeType,
            'scope_value' => $scopeType === 'all' ? null : $scopeValue,
            'product_id' => $p->id,
            'ppe_class' => $class,
            'qty' => 1,
            'is_active' => true,
        ]);
    }

    private function issue(PurchaseWorker $w, Product $p): PurchaseWorkerPpeIssue
    {
        return PurchaseWorkerPpeIssue::create([
            'tenant_id' => self::TENANT,
            'purchase_worker_id' => $w->id,
            'inventory_item_id' => $p->id,
            'item' => $p->name,
            'qty' => 1, 'returned_qty' => 0,
            'status' => 'issued', 'issued_date' => now(),
        ]);
    }

    private function ppe(): PurchasePpeService
    {
        return app(PurchasePpeService::class);
    }

    /* ── the gap this closes ─────────────────────────────────────────────── */

    /**
     * The reported weakness: one item used to satisfy everything.
     */
    public function test_one_item_no_longer_satisfies_a_two_item_requirement(): void
    {
        $w = $this->worker();
        $helmet = $this->product('Safety Helmet');
        $boots = $this->product('Safety Boots');
        $this->require($helmet);
        $this->require($boots);

        $this->issue($w, $helmet);      // equipped, but only half

        $missing = $this->ppe()->missingMandatoryFor($w->fresh());

        $this->assertSame(['Safety Boots'], $missing->pluck('name')->all());
        $this->assertFalse($this->ppe()->complianceFor($w->fresh())['compliant']);
    }

    /**
     * And the badge refusal names the item, not just "PPE".
     *
     * Taken all the way to badge-ready first, because the PPE rule sits AFTER
     * the readiness gate — a worker still missing their medical is refused for
     * that, and would never reach this check.
     */
    public function test_the_badge_refusal_names_the_missing_item(): void
    {
        $w = $this->readyWorker();
        $this->require($this->product('Safety Boots'));

        try {
            app(PurchaseWorkforceService::class)->activateBadge($w->fresh(), $this->admin);
            $this->fail('a worker short of mandatory PPE must not be badged');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('Safety Boots', $e->getMessage(),
                'naming the item is the difference between a fix and a hunt');
        }

        $this->assertNull($w->fresh()->badge_number);
    }

    /** The same worker, once equipped, is badged. */
    public function test_a_fully_equipped_worker_is_badged(): void
    {
        $w = $this->readyWorker();
        $boots = $this->product('Safety Boots');
        $this->require($boots);
        $this->issue($w, $boots);

        app(PurchaseWorkforceService::class)->activateBadge($w->fresh(), $this->admin);

        $this->assertNotNull($w->fresh()->badge_number);
    }

    /** Holding everything mandatory clears it. */
    public function test_holding_every_mandatory_item_is_compliant(): void
    {
        $w = $this->worker();
        $helmet = $this->product('Safety Helmet');
        $boots = $this->product('Safety Boots');
        $this->require($helmet);
        $this->require($boots);
        $this->issue($w, $helmet);
        $this->issue($w, $boots);

        $c = $this->ppe()->complianceFor($w->fresh());

        $this->assertTrue($c['compliant']);
        $this->assertSame([], $c['missing']);
        $this->assertTrue($c['configured']);
    }

    /**
     * The site gate refuses too, and names the item.
     *
     * The gate is the SECOND place that has to know: a badge issued before a
     * rule existed does not make the worker equipped, and the person standing at
     * the turnstile is the one the kit is for.
     */
    public function test_the_site_gate_names_the_missing_item_in_deny_mode(): void
    {
        $w = $this->readyWorker();
        $w->forceFill([
            'status' => 'Active', 'badge_number' => 'PB-1', 'qr_token' => Str::random(20),
            'badge_valid_until' => now()->addYear(),
        ])->save();

        app(\App\Services\Purchase\PurchaseSettingService::class)
            ->set(self::TENANT, 'gate_ppe_enforcement', 'deny', $this->admin);

        $this->require($this->product('Safety Boots'));

        $decision = app(PurchaseWorkforceService::class)->gateDecision($w->fresh());

        $this->assertFalse($decision['admit']);
        $this->assertStringContainsString('Safety Boots', $decision['reason']);
    }

    /**
     * In the default `warn` mode the worker is admitted AND the shortfall is
     * recorded — a log that dropped the warning would show a clean entry for
     * somebody who walked in without their boots.
     */
    public function test_warn_mode_admits_but_still_names_the_shortfall(): void
    {
        $w = $this->readyWorker();
        $w->forceFill([
            'status' => 'Active', 'badge_number' => 'PB-2', 'qr_token' => Str::random(20),
            'badge_valid_until' => now()->addYear(),
        ])->save();

        app(\App\Services\Purchase\PurchaseSettingService::class)
            ->set(self::TENANT, 'gate_ppe_enforcement', 'warn', $this->admin);

        $this->require($this->product('Safety Boots'));

        $decision = app(PurchaseWorkforceService::class)->gateDecision($w->fresh());

        $this->assertTrue($decision['admit']);
        $this->assertStringContainsString('Safety Boots', $decision['warning'] ?? '');
    }

    /* ── it must not tighten where nothing was configured ────────────────── */

    /**
     * A tenant who has not filled the matrix in keeps the old behaviour.
     *
     * Getting this wrong would lock every existing tenant out of issuing any
     * badge the moment this shipped.
     */
    public function test_with_no_rules_configured_nothing_changes(): void
    {
        $w = $this->worker();
        $this->issue($w, $this->product('Gloves'));

        $c = $this->ppe()->complianceFor($w->fresh());

        $this->assertFalse($c['configured'], 'no matrix has been set up');
        $this->assertTrue($c['compliant'], 'so holding anything still counts, as before');
        $this->assertCount(0, $this->ppe()->missingMandatoryFor($w->fresh()));
    }

    /* ── only mandatory gates ────────────────────────────────────────────── */

    /** @dataProvider advisoryClasses */
    public function test_advisory_ppe_never_blocks_a_badge(string $class): void
    {
        $w = $this->worker();
        $this->require($this->product('Hi-Vis Vest'), class: $class);

        $this->assertCount(0, $this->ppe()->missingMandatoryFor($w->fresh()),
            "{$class} PPE is advice, not a gate");
    }

    public static function advisoryClasses(): array
    {
        return ['optional' => ['optional'], 'conditional' => ['conditional']];
    }

    /* ── the rule has to match the right people ──────────────────────────── */

    public function test_a_rule_for_another_role_does_not_apply(): void
    {
        $w = $this->worker('Fitter');
        $this->require($this->product('Welding Mask'), scopeValue: 'Welder');

        $this->assertCount(0, $this->ppe()->missingMandatoryFor($w->fresh()));
    }

    public function test_an_all_workers_rule_applies_to_everyone(): void
    {
        $w = $this->worker('Anything At All');
        $this->require($this->product('Safety Helmet'), scopeType: 'all');

        $this->assertSame(['Safety Helmet'], $this->ppe()->missingMandatoryFor($w->fresh())->pluck('name')->all());
    }

    public function test_an_inactive_rule_is_ignored(): void
    {
        $w = $this->worker();
        $this->require($this->product('Safety Boots'))->update(['is_active' => false]);

        $this->assertCount(0, $this->ppe()->missingMandatoryFor($w->fresh()));
    }

    /** Returned kit is no longer held, so the requirement comes back. */
    public function test_returning_the_item_makes_it_missing_again(): void
    {
        $w = $this->worker();
        $boots = $this->product('Safety Boots');
        $this->require($boots);
        $issue = $this->issue($w, $boots);

        $this->assertCount(0, $this->ppe()->missingMandatoryFor($w->fresh()));

        $issue->update(['returned_qty' => 1]);

        $this->assertSame(['Safety Boots'], $this->ppe()->missingMandatoryFor($w->fresh())->pluck('name')->all());
    }

    /* ── who may change the rules ────────────────────────────────────────── */

    public function test_an_admin_can_add_a_rule(): void
    {
        $helmet = $this->product('Safety Helmet');
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/purchase/ppe/requirements', [
            'scope_type' => 'designation', 'scope_value' => 'Fitter',
            'product_id' => $helmet->id, 'ppe_class' => 'mandatory',
        ])->assertCreated();

        $this->assertSame(1, PurchasePpeRequirement::count());
    }

    /** Saving the same rule twice edits it — the checklist must not ask twice. */
    public function test_saving_the_same_rule_twice_does_not_duplicate_it(): void
    {
        $helmet = $this->product('Safety Helmet');
        Sanctum::actingAs($this->admin);

        foreach ([1, 2] as $qty) {
            $this->postJson('/api/purchase/ppe/requirements', [
                'scope_type' => 'designation', 'scope_value' => 'Fitter',
                'product_id' => $helmet->id, 'qty' => $qty,
            ])->assertCreated();
        }

        $this->assertSame(1, PurchasePpeRequirement::count());
        $this->assertSame(2, PurchasePpeRequirement::sole()->qty);
    }

    /** Changing what PPE is legally required is not a clerical act. */
    public function test_a_non_admin_cannot_change_the_matrix(): void
    {
        $staff = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Staff', 'role' => 'staff',
            'email' => 's-'.Str::random(5).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Sanctum::actingAs($staff);

        $this->postJson('/api/purchase/ppe/requirements', [
            'scope_type' => 'all', 'product_id' => $this->product('Safety Helmet')->id,
        ])->assertForbidden();

        $this->assertSame(0, PurchasePpeRequirement::count());
    }

    /** A rule must point at a real Inventory item. */
    public function test_a_rule_cannot_name_an_item_that_does_not_exist(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/purchase/ppe/requirements', [
            'scope_type' => 'all', 'product_id' => 999999,
        ])->assertStatus(422);
    }
}
