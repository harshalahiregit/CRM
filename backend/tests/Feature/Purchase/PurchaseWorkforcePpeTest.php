<?php

namespace Tests\Feature\Purchase;

use App\Models\Inventory\Movement;
use App\Models\Inventory\Product;
use App\Models\Inventory\Warehouse;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Purchase\PurchasePpeService;
use App\Services\Purchase\PurchaseWorkforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Purchase workforce steps 1–5, and PPE against the CENTRAL inventory ledger.
 *
 * The point of most of these is that Purchase keeps no stock of its own: every
 * issue and return moves inventory_stock through StockService and leaves a row in
 * inventory_movements, which is what makes a hand-out visible in Admin Inventory.
 *
 * current_step holds the HIGHEST step completed (1 create · 2 medical · 3
 * training+induction · 4 PPE · 5 badge), so the wizard resumes from the database
 * rather than from React state.
 */
class PurchaseWorkforcePpeTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseWorkforceService $wf;
    private PurchasePpeService $ppe;
    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();

        $this->wf    = app(PurchaseWorkforceService::class);
        $this->ppe   = app(PurchasePpeService::class);
        $this->stock = app(StockService::class);
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(string $n): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $n,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower($n).'@test.local', 'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function worker(PurchaseVendor $v, string $n = 'Worker'): PurchaseWorker
    {
        return $this->wf->create($v, [
            'full_name' => $n, 'dob' => '1990-01-01', 'designation' => 'Fitter', 'phone' => '9990000000',
        ]);
    }

    /** A worker taken all the way to "PPE issued", ready for a badge. */
    private function readyWorker(PurchaseVendor $v): PurchaseWorker
    {
        $w = $this->worker($v);
        $this->wf->addDocument($w, 'id_proof', UploadedFile::fake()->create('id.pdf', 5));
        $this->wf->saveMedical($w, ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()]);
        $this->wf->saveTraining($w, ['title' => 'Safety', 'status' => 'Completed']);
        $this->wf->saveInduction($w, ['status' => 'Completed']);

        return $w->fresh();
    }

    private function stockedProduct(float $qty = 100): array
    {
        $wh = Warehouse::create([
            'tenant_id' => self::TENANT, 'name' => 'Main', 'code' => 'WH1', 'is_default' => true,
        ]);
        $p = Product::create([
            'tenant_id' => self::TENANT, 'name' => 'Safety Helmet',
            'sku' => 'HELM-'.Str::random(4), 'status' => 'Active',
        ]);
        $this->stock->adjustTo($p->id, $wh->id, $qty, self::TENANT, null, 'baseline');

        return [$p, $wh];
    }

    private function available(Product $p): float
    {
        return (float) ($this->stock->totalsFor($p->id, self::TENANT)['available'] ?? 0);
    }

    /* ── Steps 1–3 ──────────────────────────────────────────────────── */

    public function test_worker_is_created_against_the_authenticated_vendor(): void
    {
        $v = $this->vendor('OwnerCo');
        $w = $this->worker($v);

        $this->assertSame((int) $v->id, (int) $w->purchase_vendor_id);
        $this->assertSame(1, (int) $w->fresh()->current_step);
    }

    public function test_unfit_medical_does_not_advance_the_worker(): void
    {
        $w = $this->worker($this->vendor('MedCo'));

        $this->wf->saveMedical($w, ['fitness_status' => 'Unfit', 'exam_date' => now()->toDateString()]);

        $this->assertSame(1, (int) $w->fresh()->current_step);
    }

    public function test_fit_medical_advances_to_step_two(): void
    {
        $w = $this->worker($this->vendor('FitCo'));

        $this->wf->saveMedical($w, ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()]);

        $this->assertSame(2, (int) $w->fresh()->current_step);
    }

    /** Step 3 needs BOTH — a completed training alone is not induction. */
    public function test_training_and_induction_together_advance_to_step_three(): void
    {
        $w = $this->worker($this->vendor('TrainCo'));
        $this->wf->saveMedical($w, ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()]);

        $this->wf->saveTraining($w, ['title' => 'Safety', 'status' => 'Pending']);
        $this->assertSame(2, (int) $w->fresh()->current_step);

        $this->wf->saveTraining($w, ['title' => 'Safety', 'status' => 'Completed']);
        $this->assertSame(2, (int) $w->fresh()->current_step, 'Induction is still outstanding.');

        $this->wf->saveInduction($w, ['status' => 'Completed']);
        $this->assertSame(3, (int) $w->fresh()->current_step);
    }

    /** The pointer is DB state — this is what makes refresh/resume work. */
    public function test_step_progress_is_persisted(): void
    {
        $w = $this->worker($this->vendor('ResumeCo'));
        $this->wf->saveMedical($w, ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()]);

        $this->assertSame(2, (int) PurchaseWorker::find($w->id)->current_step);
    }

    /* ── Step 4 — PPE against central inventory ─────────────────────── */

    public function test_issue_moves_central_stock_and_writes_a_movement(): void
    {
        [$p, $wh] = $this->stockedProduct(100);
        $w = $this->readyWorker($this->vendor('IssueCo'));

        $this->assertSame(100.0, $this->available($p));

        $issue = $this->ppe->issue($w, ['inventory_item_id' => $p->id, 'qty' => 2]);

        $this->assertSame(98.0, $this->available($p));
        $this->assertSame(4, (int) $w->fresh()->current_step);

        $mv = Movement::where('reference_type', 'purchase_ppe_issue')
            ->where('reference_id', $issue->id)->firstOrFail();

        $this->assertSame('out', $mv->direction);
        $this->assertSame((int) $p->id, (int) $mv->product_id);
        $this->assertEqualsWithDelta(2, (float) $mv->quantity, 0.001);
        $this->assertSame((int) $wh->id, (int) $mv->from_warehouse_id);
        $this->assertEqualsWithDelta(98, (float) $mv->balance_after, 0.001);
    }

    public function test_over_issue_is_rejected_and_leaves_stock_untouched(): void
    {
        [$p] = $this->stockedProduct(1);
        $w = $this->readyWorker($this->vendor('OverCo'));

        try {
            $this->ppe->issue($w, ['inventory_item_id' => $p->id, 'qty' => 2]);
            $this->fail('Over-issue should have been rejected.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('Insufficient stock', $e->getMessage());
        }

        $this->assertSame(1.0, $this->available($p));
        $this->assertDatabaseCount('purchase_worker_ppe_issues', 0);
    }

    public function test_stock_never_goes_negative(): void
    {
        [$p] = $this->stockedProduct(1);
        $w = $this->readyWorker($this->vendor('ZeroCo'));

        $this->ppe->issue($w, ['inventory_item_id' => $p->id, 'qty' => 1]);
        $this->assertSame(0.0, $this->available($p));

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->ppe->issue($w, ['inventory_item_id' => $p->id, 'qty' => 1]);
    }

    public function test_genuine_return_restocks_the_original_warehouse(): void
    {
        [$p, $wh] = $this->stockedProduct(100);
        $w = $this->readyWorker($this->vendor('ReturnCo'));

        $issue = $this->ppe->issue($w, ['inventory_item_id' => $p->id, 'qty' => 2]);
        $this->assertSame(98.0, $this->available($p));

        $this->ppe->returnIssue($issue->fresh(), ['condition' => 'returned']);

        $this->assertSame(100.0, $this->available($p));

        $back = Movement::where('reference_type', 'purchase_ppe_issue')
            ->where('reference_id', $issue->id)->where('direction', 'in')->firstOrFail();

        $this->assertSame((int) $wh->id, (int) $back->to_warehouse_id);
    }

    /** Lost/damaged kit left stock at ISSUE time — restocking would double-count. */
    public function test_lost_and_damaged_do_not_restock(): void
    {
        [$p] = $this->stockedProduct(100);
        $w = $this->readyWorker($this->vendor('LossCo'));

        $lost = $this->ppe->issue($w, ['inventory_item_id' => $p->id, 'qty' => 1]);
        $this->ppe->returnIssue($lost->fresh(), ['condition' => 'lost']);
        $this->assertSame(99.0, $this->available($p));

        $dmg = $this->ppe->issue($w, ['inventory_item_id' => $p->id, 'qty' => 1]);
        $this->ppe->returnIssue($dmg->fresh(), ['condition' => 'damaged']);
        $this->assertSame(98.0, $this->available($p));
    }

    /* ── Step 5 — badge & gate ──────────────────────────────────────── */

    public function test_badge_requires_ppe_and_readiness(): void
    {
        $w = $this->worker($this->vendor('EarlyCo'));   // nothing done

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->wf->activateBadge($w, $this->admin());
    }

    public function test_admin_activation_issues_a_badge_and_reaches_step_five(): void
    {
        [$p] = $this->stockedProduct(10);
        $w = $this->readyWorker($this->vendor('BadgeCo'));
        $this->ppe->issue($w, ['inventory_item_id' => $p->id, 'qty' => 1]);

        $this->wf->activateBadge($w->fresh(), $this->admin());
        $w = $w->fresh();

        $this->assertSame(5, (int) $w->current_step);
        $this->assertSame('Active', $w->status);
        $this->assertNotEmpty($w->badge_number);
        $this->assertNotEmpty($w->qr_token);
    }

    public function test_gate_denies_before_activation_and_admits_after(): void
    {
        [$p] = $this->stockedProduct(10);
        $w = $this->readyWorker($this->vendor('GateCo'));
        $this->ppe->issue($w, ['inventory_item_id' => $p->id, 'qty' => 1]);

        $this->assertFalse($this->wf->gateDecision($w->fresh())['admit']);

        $this->wf->activateBadge($w->fresh(), $this->admin());

        $this->assertTrue($this->wf->gateDecision($w->fresh())['admit']);
    }

    /* ── Ownership ──────────────────────────────────────────────────── */

    public function test_a_vendor_cannot_resolve_another_vendors_worker(): void
    {
        $a = $this->vendor('VendA');
        $b = $this->vendor('VendB');
        $wb = $this->worker($b, 'B Worker');

        $this->assertNull($this->wf->find($a, $wb->id), 'Vendor A must not reach vendor B\'s worker.');
        $this->assertNotNull($this->wf->find($b, $wb->id));
    }

    public function test_ppe_summary_is_scoped_to_the_vendors_own_issues(): void
    {
        [$p] = $this->stockedProduct(100);
        $a = $this->vendor('SumA');
        $b = $this->vendor('SumB');

        $wa = $this->readyWorker($a);
        $this->ppe->issue($wa, ['inventory_item_id' => $p->id, 'qty' => 3]);

        $mine   = $this->ppe->summaryForVendor((int) $a->id, self::TENANT);
        $theirs = $this->ppe->summaryForVendor((int) $b->id, self::TENANT);

        $this->assertEqualsWithDelta(3, $mine['total_issued'], 0.001);
        // B holds nothing, and must not be shown A's figures.
        $this->assertEqualsWithDelta(0, $theirs['total_issued'], 0.001);
        // The shelf is shared, so availability is the same for both.
        $this->assertEqualsWithDelta($mine['total_available'], $theirs['total_available'], 0.001);
    }
}
