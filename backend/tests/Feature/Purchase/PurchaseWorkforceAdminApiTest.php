<?php

namespace Tests\Feature\Purchase;

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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The ADMIN half of the Purchase workforce — the HTTP surface the new admin
 * screens call.
 *
 * The authority split is the point of most of these: staff review, admins admit.
 * The UI hides the activate button for staff, but the endpoint is the real
 * boundary and is tested here directly rather than through the button.
 */
class PurchaseWorkforceAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach ([self::TENANT, 999] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => 'T'.$id, 'slug' => 't'.$id,
                'subdomain' => 't'.$id, 'status' => 'active',
            ])->save();
        }
    }

    private function user(string $role, int $tenant = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenant, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(string $n, int $tenant = self::TENANT): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => $tenant, 'company_name' => $n,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower($n).Str::random(3).'@test.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    /**
     * ONE default warehouse + stocked product per tenant.
     *
     * Created once rather than per worker: a second warehouse flagged is_default
     * would be resolved ahead of the one the stock was added to, and the issue
     * would fail on an empty shelf.
     */
    private array $shelves = [];

    private function shelf(int $tenant = self::TENANT): array
    {
        // An instance property, not a method static: a static would outlive
        // RefreshDatabase and hand the next test ids that no longer exist.
        if (! isset($this->shelves[$tenant])) {
            $wh = Warehouse::create(['tenant_id' => $tenant, 'name' => 'WH'.$tenant, 'code' => 'W'.Str::random(4), 'is_default' => true]);
            $p  = Product::create(['tenant_id' => $tenant, 'name' => 'Helmet', 'sku' => 'H-'.Str::random(5), 'status' => 'Active']);
            $this->shelves[$tenant] = [$wh, $p];
        }

        [$wh, $p] = $this->shelves[$tenant];
        // Top the shelf up per worker so a test issuing several still passes.
        app(StockService::class)->adjustTo($p->id, $wh->id, 50, $tenant, null, 'baseline');

        return [$wh, $p];
    }

    /** A worker taken to "PPE issued" — everything an activation needs. */
    private function readyWorker(PurchaseVendor $v): PurchaseWorker
    {
        $wf  = app(PurchaseWorkforceService::class);
        $ppe = app(PurchasePpeService::class);

        $w = $wf->create($v, ['full_name' => 'Worker '.Str::random(4), 'dob' => '1990-01-01', 'designation' => 'Fitter']);
        $wf->addDocument($w, 'id_proof', UploadedFile::fake()->create('id.pdf', 5));
        $wf->saveMedical($w, ['fitness_status' => 'Fit', 'exam_date' => now()->toDateString()]);
        $wf->saveTraining($w, ['title' => 'Safety', 'status' => 'Completed']);
        $wf->saveInduction($w, ['status' => 'Completed']);

        [, $p] = $this->shelf((int) $v->tenant_id);
        $ppe->issue($w->fresh(), ['inventory_item_id' => $p->id, 'qty' => 1]);

        return $w->fresh();
    }

    public function test_admin_can_list_purchase_workers(): void
    {
        $v = $this->vendor('ListCo');
        $this->readyWorker($v);

        Sanctum::actingAs($this->user('admin'));

        $this->getJson('/api/purchase/workforce/workers')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** vendor_id filters the list; it never authorises it. */
    public function test_vendor_filter_narrows_the_list(): void
    {
        $a = $this->vendor('FilterA');
        $b = $this->vendor('FilterB');
        $this->readyWorker($a);
        $this->readyWorker($b);

        Sanctum::actingAs($this->user('admin'));

        $this->getJson('/api/purchase/workforce/workers')->assertJsonCount(2, 'data');
        $this->getJson('/api/purchase/workforce/workers?vendor_id='.$a->id)->assertJsonCount(1, 'data');
    }

    public function test_admin_can_open_a_worker_with_readiness_and_badge(): void
    {
        $w = $this->readyWorker($this->vendor('OpenCo'));

        Sanctum::actingAs($this->user('admin'));

        $this->getJson("/api/purchase/workforce/workers/{$w->id}")
            ->assertOk()
            ->assertJsonPath('worker.id', $w->id)
            ->assertJsonPath('badge.activated', false)
            ->assertJsonStructure(['worker', 'readiness' => ['documents_ok', 'medical_ok', 'training_ok', 'induction_ok', 'ready'], 'badge']);
    }

    public function test_admin_can_view_worker_ppe(): void
    {
        $w = $this->readyWorker($this->vendor('PpeCo'));

        Sanctum::actingAs($this->user('admin'));

        $this->getJson("/api/purchase/workforce/workers/{$w->id}/ppe")
            ->assertOk()
            ->assertJsonCount(1, 'issues')
            ->assertJsonPath('compliance.compliant', true);
    }

    public function test_admin_activation_sets_badge_step_five_and_gate_admit(): void
    {
        $w = $this->readyWorker($this->vendor('ActCo'));

        Sanctum::actingAs($this->user('admin'));

        $this->getJson("/api/purchase/workforce/workers/{$w->id}/gate")
            ->assertOk()->assertJsonPath('admit', false);

        $this->postJson("/api/purchase/workforce/workers/{$w->id}/activate")->assertOk();

        $w = $w->fresh();
        $this->assertSame(5, (int) $w->current_step);
        $this->assertSame('Active', $w->status);
        $this->assertNotEmpty($w->badge_number);

        $this->getJson("/api/purchase/workforce/workers/{$w->id}/gate")
            ->assertOk()->assertJsonPath('admit', true);
    }

    /** Staff review, admins admit. The endpoint is the boundary, not the button. */
    public function test_staff_cannot_activate_a_worker(): void
    {
        $w = $this->readyWorker($this->vendor('StaffCo'));

        Sanctum::actingAs($this->user('staff'));

        $this->postJson("/api/purchase/workforce/workers/{$w->id}/activate")->assertForbidden();

        $this->assertNull($w->fresh()->badge_number);
    }

    /** Staff still need the review screens, so reads stay open to them. */
    public function test_staff_can_still_review(): void
    {
        $w = $this->readyWorker($this->vendor('ReviewCo'));

        Sanctum::actingAs($this->user('staff'));

        $this->getJson('/api/purchase/workforce/workers')->assertOk();
        $this->getJson("/api/purchase/workforce/workers/{$w->id}")->assertOk();
        $this->getJson("/api/purchase/workforce/workers/{$w->id}/ppe")->assertOk();
    }

    public function test_vendor_roles_cannot_reach_the_admin_workforce_api(): void
    {
        $w = $this->readyWorker($this->vendor('DenyCo'));

        foreach (['vendor', 'third_party_vendor'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->getJson('/api/purchase/workforce/workers')->assertForbidden();
            $this->postJson("/api/purchase/workforce/workers/{$w->id}/activate")->assertForbidden();
        }
    }

    /** 404, not 403 — the same existence-hiding the rest of Purchase uses. */
    public function test_admin_cannot_reach_another_tenants_worker(): void
    {
        $foreign = $this->readyWorker($this->vendor('ForeignCo', 999));

        Sanctum::actingAs($this->user('admin'));

        $this->getJson("/api/purchase/workforce/workers/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/purchase/workforce/workers/{$foreign->id}/activate")->assertNotFound();
    }

    /** An unready worker cannot be badged, even by an admin. */
    public function test_activation_is_refused_before_the_worker_is_ready(): void
    {
        $v = $this->vendor('EarlyCo');
        $w = app(PurchaseWorkforceService::class)
            ->create($v, ['full_name' => 'Bare', 'dob' => '1990-01-01', 'designation' => 'Fitter']);

        Sanctum::actingAs($this->user('admin'));

        $this->postJson("/api/purchase/workforce/workers/{$w->id}/activate")->assertStatus(422);
        $this->assertNull($w->fresh()->badge_number);
    }
}
