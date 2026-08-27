<?php

namespace Tests\Feature\Tpv;

use App\Models\Inventory\Product;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Warehouse;
use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerPpeIssue;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\PpeInventoryService;
use App\Services\Tpv\TpvCompetencyService;
use App\Services\Tpv\TpvWorkerService;
use App\Support\Tpv\TpvMedicalFitness as Fitness;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §16 Medical (Pending status + distinct sign-off + certificate/document upload),
 * §17 PPE (atomic Replacement + Used status + vendor stock), §15 Competency
 * (experience field). Field/status additions from the doc gap list.
 */
class MedicalPpeCompetencyFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
        $this->warehouse = Warehouse::create([
            'tenant_id' => self::TENANT, 'name' => 'Main', 'code' => 'MAIN',
            'type' => 'godown', 'is_default' => true, 'status' => 'active',
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function worker(): TpvWorker
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);

        return TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'name' => 'Ravi',
            'current_step' => 1, 'status' => 'Draft',
        ]);
    }

    private function product(float $qty = 100): Product
    {
        $p = Product::create([
            'tenant_id' => self::TENANT, 'name' => 'Helmet', 'sku' => 'PPE-'.Str::random(6),
            'base_unit' => 'pcs', 'status' => 'active', 'without_checking_warehouse' => false,
        ]);
        Stock::create([
            'tenant_id' => self::TENANT, 'product_id' => $p->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => $qty, 'reserved_quantity' => 0,
        ]);

        return $p;
    }

    private function onHand(Product $p): float
    {
        return (float) Stock::where('product_id', $p->id)->sum('quantity');
    }

    /* ── §16 Medical ──────────────────────────────────────────────────── */

    public function test_pending_status_and_signoff_and_documents_persist(): void
    {
        $worker = $this->worker();
        $admin  = $this->admin();
        $signoff = $this->admin();

        app(TpvWorkerService::class)->saveMedical($worker, [
            'fitness_status'   => Fitness::PENDING,
            'approved_by'      => $signoff->id,
            'approved_at'      => now()->toDateTimeString(),
            'certificate_path' => 'medicals/cert-1.pdf',
            'document_path'    => 'medicals/doc-1.pdf',
        ], $admin);

        $m = $worker->fresh('medical')->medical;
        $this->assertSame(Fitness::PENDING, $m->fitness_status);
        $this->assertSame($signoff->id, $m->approved_by);
        $this->assertSame('medicals/cert-1.pdf', $m->certificate_path);
        $this->assertSame('medicals/doc-1.pdf', $m->document_path);
        // Pending is not a passing outcome — it must not clear the badge gate.
        $this->assertFalse($m->isPassing());
    }

    public function test_expired_is_a_valid_stored_status(): void
    {
        $this->assertContains(Fitness::EXPIRED, Fitness::ALL);
        $this->assertFalse(Fitness::isPassing(Fitness::EXPIRED));
    }

    /* ── §15 Competency ───────────────────────────────────────────────── */

    public function test_competency_experience_persists(): void
    {
        $worker = $this->worker();

        $c = app(TpvCompetencyService::class)->addCompetency($worker, [
            'name' => 'Welding', 'category' => 'Skill', 'experience_years' => 7.5,
        ]);

        $this->assertSame('7.5', (string) $c->fresh()->experience_years);
    }

    /* ── §17 PPE ──────────────────────────────────────────────────────── */

    public function test_replacement_closes_the_old_issue_and_draws_fresh_kit(): void
    {
        $worker = $this->worker();
        $item   = $this->product(10);
        $ppe    = app(PpeInventoryService::class);

        $issue = $ppe->issue($worker, ['inventory_item_id' => $item->id, 'qty' => 1], $this->admin());
        $this->assertSame(9.0, $this->onHand($item));

        $fresh = $ppe->replaceIssue($issue, [], $this->admin());

        // Old issue closed as replaced and chained to the new one.
        $old = $issue->fresh();
        $this->assertSame(TpvWorkerPpeIssue::STATUS_REPLACED, $old->status);
        $this->assertSame($fresh->id, $old->replaced_by_id);

        // New issue is live, and stock moved again (worn kit is discarded, not returned).
        $this->assertSame(TpvWorkerPpeIssue::STATUS_ISSUED, $fresh->status);
        $this->assertSame(8.0, $this->onHand($item));
    }

    public function test_mark_used_is_terminal_and_moves_no_stock(): void
    {
        $worker = $this->worker();
        $item   = $this->product(10);
        $ppe    = app(PpeInventoryService::class);

        $issue = $ppe->issue($worker, ['inventory_item_id' => $item->id, 'qty' => 1], $this->admin());
        $after = $this->onHand($item);

        $used = $ppe->markUsed($issue, [], $this->admin());

        $this->assertSame(TpvWorkerPpeIssue::STATUS_USED, $used->status);
        $this->assertSame($after, $this->onHand($item));
    }

    public function test_project_scope_defaults_from_the_worker(): void
    {
        $worker = $this->worker();
        $worker->update(['project' => 'Refinery', 'site' => 'Unit 4']);
        $item = $this->product(10);

        $issue = app(PpeInventoryService::class)->issue($worker, ['inventory_item_id' => $item->id, 'qty' => 1], $this->admin());

        $this->assertSame('Refinery', $issue->fresh()->project);
        $this->assertSame('Unit 4', $issue->fresh()->site);
    }

    public function test_vendor_ppe_stock_reports_available(): void
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'StockCo', 'status' => VendorStatus::ACTIVE]);
        $row = \App\Models\Tpv\TpvVendorPpeStock::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'item' => 'Helmet',
            'allocated_qty' => 50, 'issued_qty' => 12,
        ]);

        $this->assertSame(38.0, $row->fresh()->available_qty);
    }
}
