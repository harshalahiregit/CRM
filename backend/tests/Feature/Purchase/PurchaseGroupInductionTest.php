<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerInduction;
use App\Models\Purchase\PurchaseWorkerMedical;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Medical\MedicalQcStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Group induction on the Purchase side — admin (/purchase/workforce) and the
 * vendor portal (/portal/purchase). One session, many workers, the TRAINER
 * signs once.
 *
 * The same four guarantees as the TPV engine (GroupInductionTest), exercised
 * separately because the two share a vocabulary, not a code path.
 */
class PurchaseGroupInductionTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private const DISK = 'purchase_docs';

    /** A 1x1 PNG — the smallest thing that reads as a real image. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(self::DISK);

        foreach ([self::TENANT, 2] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => "Tenant {$id}", 'slug' => "tenant-{$id}",
                'subdomain' => "tenant{$id}", 'status' => 'active',
            ])->save();
        }
    }

    /* ── Fixtures ─────────────────────────────────────────────────────── */

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name, int $tenant = self::TENANT): PurchaseVendor
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => $tenant, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower($name).'-'.Str::random(4).'@test.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
        $this->markOnboarded($vendor);

        return $vendor->fresh();
    }

    /** A Pending worker; `$cleared` gives it a Fit medical the quality team approved. */
    private function worker(PurchaseVendor $v, bool $cleared = true, string $name = 'Worker'): PurchaseWorker
    {
        $w = PurchaseWorker::create([
            'tenant_id' => $v->tenant_id, 'purchase_vendor_id' => $v->id,
            'worker_code' => 'PW-'.Str::random(6), 'full_name' => $name,
            'designation' => 'Rigger', 'current_step' => 1, 'status' => 'Pending',
        ]);

        if ($cleared) {
            PurchaseWorkerMedical::create([
                'tenant_id' => $v->tenant_id, 'purchase_vendor_id' => $v->id, 'purchase_worker_id' => $w->id,
                'fitness_status' => 'Fit', 'exam_date' => now()->toDateString(),
            ])->forceFill(['qc_status' => MedicalQcStatus::APPROVED, 'qc_at' => now()])->save();
        }

        return $w;
    }

    private function payload(array $ids): array
    {
        return [
            'worker_ids'     => $ids,
            'induction_date' => now()->toDateString(),
            'status'         => 'Completed',
            'conducted_by'   => 'Safety Officer',
            'trainer_name'   => 'Safety Officer',
            'topics'         => ['Site Safety Rules', 'PPE Usage'],
            'remarks'        => "Type: General Safety\nLocation: Site Office",
            'signature_data' => self::PNG,
        ];
    }

    /* ── Admin ────────────────────────────────────────────────────────── */

    public function test_admin_saves_one_session_for_many_workers(): void
    {
        $v = $this->vendor('Acme');
        $ids = collect(range(1, 5))->map(fn ($i) => $this->worker($v, true, "W{$i}")->id)->all();

        Sanctum::actingAs($this->admin());
        $res = $this->postJson('/api/purchase/workforce/workers/bulk-induction', $this->payload($ids))->assertOk();

        $this->assertCount(5, $res->json('saved'));
        $this->assertSame([], $res->json('skipped'));
        $this->assertSame(5, PurchaseWorkerInduction::count());

        $row = PurchaseWorkerInduction::first();
        $this->assertSame('Completed', $row->status);
        $this->assertSame('Safety Officer', $row->conducted_by);
        $this->assertNotNull($row->recorded_by);
    }

    public function test_admin_skips_uncleared_workers_with_a_reason(): void
    {
        $v = $this->vendor('Acme');
        $ok = $this->worker($v, true, 'Cleared');
        $blocked = $this->worker($v, false, 'No Medical');

        Sanctum::actingAs($this->admin());
        $res = $this->postJson('/api/purchase/workforce/workers/bulk-induction', $this->payload([$ok->id, $blocked->id]))
            ->assertOk();

        $this->assertSame([$ok->id], $res->json('saved'));
        $this->assertSame($blocked->id, $res->json('skipped.0.id'));
        $this->assertSame('No Medical', $res->json('skipped.0.name'));
        $this->assertStringContainsString('Safety induction is blocked', $res->json('skipped.0.reason'));
        $this->assertSame(0, PurchaseWorkerInduction::where('purchase_worker_id', $blocked->id)->count());
    }

    public function test_admin_cannot_reach_another_tenants_worker(): void
    {
        $foreign = $this->worker($this->vendor('Elsewhere', 2));

        Sanctum::actingAs($this->admin());
        $res = $this->postJson('/api/purchase/workforce/workers/bulk-induction', $this->payload([$foreign->id]))
            ->assertOk();

        $this->assertSame([], $res->json('saved'));
        $this->assertSame('Worker not found.', $res->json('skipped.0.reason'));
        $this->assertSame(0, PurchaseWorkerInduction::count());
    }

    public function test_the_trainer_signature_is_stored_once_and_shared(): void
    {
        $v = $this->vendor('Acme');
        $ids = collect(range(1, 4))->map(fn () => $this->worker($v)->id)->all();

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/purchase/workforce/workers/bulk-induction', $this->payload($ids))->assertOk();

        $this->assertCount(1, Storage::disk(self::DISK)->allFiles('workers/induction'));
        $paths = PurchaseWorkerInduction::pluck('signature_path')->unique();
        $this->assertCount(1, $paths);
        Storage::disk(self::DISK)->assertExists($paths->first());
    }

    public function test_remarks_longer_than_the_column_are_refused_up_front(): void
    {
        $v = $this->vendor('Acme');
        Sanctum::actingAs($this->admin());

        $payload = $this->payload([$this->worker($v)->id]);
        $payload['remarks'] = str_repeat('x', 501);

        $this->postJson('/api/purchase/workforce/workers/bulk-induction', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('remarks');
    }

    /* ── Vendor portal ────────────────────────────────────────────────── */

    public function test_portal_saves_own_workers_and_skips_uncleared(): void
    {
        $vendor = $this->vendor('Acme');
        $a = $this->worker($vendor, true, 'A');
        $b = $this->worker($vendor, true, 'B');
        $c = $this->worker($vendor, false, 'C');

        Sanctum::actingAs($vendor);
        $res = $this->postJson('/api/portal/purchase/workers/bulk-induction', $this->payload([$a->id, $b->id, $c->id]))
            ->assertOk();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $res->json('saved'));
        $this->assertSame($c->id, $res->json('skipped.0.id'));
        $this->assertStringContainsString('Safety induction is blocked', $res->json('skipped.0.reason'));
        $this->assertCount(1, Storage::disk(self::DISK)->allFiles('workers/induction'));
        $this->assertCount(1, PurchaseWorkerInduction::pluck('signature_path')->unique());
    }

    public function test_portal_vendor_cannot_induct_another_vendors_worker(): void
    {
        $mineVendor = $this->vendor('Alpha');
        $otherVendor = $this->vendor('Bravo');
        $mine = $this->worker($mineVendor, true, 'Mine');
        $theirs = $this->worker($otherVendor, true, 'Theirs');

        Sanctum::actingAs($mineVendor);
        $res = $this->postJson('/api/portal/purchase/workers/bulk-induction', $this->payload([$mine->id, $theirs->id]))
            ->assertOk();

        $this->assertSame([$mine->id], $res->json('saved'));
        $this->assertSame($theirs->id, $res->json('skipped.0.id'));
        $this->assertNull($res->json('skipped.0.name'));
        $this->assertSame('Worker not found.', $res->json('skipped.0.reason'));
        $this->assertSame(0, PurchaseWorkerInduction::where('purchase_worker_id', $theirs->id)->count());
    }

    public function test_portal_session_needs_the_trainer_signature(): void
    {
        $vendor = $this->vendor('Acme');
        Sanctum::actingAs($vendor);

        $payload = $this->payload([$this->worker($vendor)->id]);
        unset($payload['signature_data']);

        $this->postJson('/api/portal/purchase/workers/bulk-induction', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('signature_data');
    }
}
