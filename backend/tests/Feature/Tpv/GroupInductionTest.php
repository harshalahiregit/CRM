<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerInduction;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Group induction — one session, many workers, the TRAINER signs once.
 *
 * Pinned for both TPV surfaces (admin /tpv and vendor /portal):
 *   - many workers are saved in one request;
 *   - a worker without medical clearance is SKIPPED with a human reason, and
 *     does not stop the rest of the group;
 *   - a vendor cannot induct another vendor's worker (reported "not found");
 *   - the trainer signature is decoded and stored ONCE, and that one path is
 *     written on every inducted worker's record.
 */
class GroupInductionTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    /** A 1x1 PNG — the smallest thing that reads as a real image. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        foreach ([self::TENANT, 2] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => "Tenant {$id}", 'slug' => "tenant-{$id}",
                'subdomain' => "tenant{$id}", 'status' => 'active',
            ])->save();
        }
    }

    /* ── Fixtures ─────────────────────────────────────────────────────── */

    private function user(string $role, int $tenant = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenant, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    /** A vendor company plus the portal login that owns it. */
    private function vendorWithLogin(string $name): array
    {
        $user = $this->user('third_party_vendor');
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => strtolower($name).'-'.Str::random(6).'@vendor.local',
            'status' => VendorStatus::ACTIVE, 'user_id' => $user->id,
        ]);
        $this->markOnboarded($vendor);

        return [$user, $vendor];
    }

    private function vendor(string $name, int $tenant = self::TENANT): Vendor
    {
        return Vendor::create(['tenant_id' => $tenant, 'company_name' => $name, 'status' => VendorStatus::ACTIVE]);
    }

    /** A Draft worker; `$cleared` marks medical as not applicable (the legacy skip). */
    private function worker(Vendor $v, bool $cleared = true, string $name = 'Worker'): TpvWorker
    {
        return TpvWorker::create([
            'tenant_id' => $v->tenant_id, 'vendor_id' => $v->id,
            'worker_code' => 'W-'.Str::random(6), 'name' => $name,
            'current_step' => 1, 'status' => 'Draft',
            ...($cleared ? ['medical_status' => 2, 'medical_type' => 'skip'] : []),
        ]);
    }

    private function payload(array $ids): array
    {
        return [
            'worker_ids'     => $ids,
            'induction_type' => 'General Safety',
            'trainer'        => 'Safety Officer',
            'location'       => 'Site Office',
            'topics'         => ['Site Safety Rules', 'PPE Usage'],
            'passed'         => true,
            'signature_data' => self::PNG,
        ];
    }

    /* ── Admin ────────────────────────────────────────────────────────── */

    public function test_admin_saves_one_session_for_many_workers(): void
    {
        $v = $this->vendor('Acme');
        $workers = collect(range(1, 5))->map(fn ($i) => $this->worker($v, true, "W{$i}"));

        Sanctum::actingAs($this->user('admin'));
        $res = $this->postJson('/api/tpv/workers/bulk-induction', $this->payload($workers->pluck('id')->all()))
            ->assertOk();

        $this->assertCount(5, $res->json('saved'));
        $this->assertSame([], $res->json('skipped'));
        $this->assertSame(5, TpvWorkerInduction::count());

        $row = TpvWorkerInduction::first();
        $this->assertSame('Safety Officer', $row->trainer_name);
        $this->assertSame(['Site Safety Rules', 'PPE Usage'], $row->topics);
        $this->assertTrue($row->passed);
        $this->assertSame(3, (int) $workers->first()->fresh()->current_step);
    }

    public function test_admin_skips_uncleared_workers_with_a_reason_and_saves_the_rest(): void
    {
        $v = $this->vendor('Acme');
        $ok = $this->worker($v, true, 'Cleared');
        $blocked = $this->worker($v, false, 'No Medical');

        Sanctum::actingAs($this->user('admin'));
        $res = $this->postJson('/api/tpv/workers/bulk-induction', $this->payload([$ok->id, $blocked->id]))
            ->assertOk();

        $this->assertSame([$ok->id], $res->json('saved'));
        $this->assertCount(1, $res->json('skipped'));
        $this->assertSame($blocked->id, $res->json('skipped.0.id'));
        $this->assertSame('No Medical', $res->json('skipped.0.name'));
        $this->assertStringContainsString('Safety induction is blocked', $res->json('skipped.0.reason'));
        $this->assertNull($blocked->fresh()->induction);
    }

    public function test_admin_cannot_reach_another_tenants_worker(): void
    {
        $foreign = $this->worker($this->vendor('Elsewhere', 2));

        Sanctum::actingAs($this->user('admin'));
        $res = $this->postJson('/api/tpv/workers/bulk-induction', $this->payload([$foreign->id]))->assertOk();

        $this->assertSame([], $res->json('saved'));
        $this->assertSame('Worker not found.', $res->json('skipped.0.reason'));
        $this->assertSame(0, TpvWorkerInduction::count());
        $this->assertSame(2, (int) $foreign->fresh()->tenant_id);
    }

    public function test_the_trainer_signature_is_stored_once_and_shared(): void
    {
        $v = $this->vendor('Acme');
        $ids = collect(range(1, 4))->map(fn () => $this->worker($v)->id)->all();

        Sanctum::actingAs($this->user('admin'));
        $this->postJson('/api/tpv/workers/bulk-induction', $this->payload($ids))->assertOk();

        $this->assertCount(1, Storage::disk('public')->allFiles('workers/induction'));
        $paths = TpvWorkerInduction::pluck('signature_path')->unique();
        $this->assertCount(1, $paths);
        Storage::disk('public')->assertExists($paths->first());
    }

    public function test_a_session_needs_the_trainer_signature(): void
    {
        $v = $this->vendor('Acme');
        Sanctum::actingAs($this->user('admin'));

        $payload = $this->payload([$this->worker($v)->id]);
        unset($payload['signature_data']);

        $this->postJson('/api/tpv/workers/bulk-induction', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('signature_data');
    }

    /* ── Vendor portal ────────────────────────────────────────────────── */

    public function test_portal_saves_own_workers_and_skips_uncleared(): void
    {
        [$user, $vendor] = $this->vendorWithLogin('Acme');
        $a = $this->worker($vendor, true, 'A');
        $b = $this->worker($vendor, true, 'B');
        $c = $this->worker($vendor, false, 'C');

        Sanctum::actingAs($user);
        $res = $this->postJson('/api/portal/workers/bulk-induction', $this->payload([$a->id, $b->id, $c->id]))
            ->assertOk();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $res->json('saved'));
        $this->assertSame($c->id, $res->json('skipped.0.id'));
        $this->assertStringContainsString('Safety induction is blocked', $res->json('skipped.0.reason'));
        $this->assertCount(1, Storage::disk('public')->allFiles('workers/induction'));
        $this->assertCount(1, TpvWorkerInduction::pluck('signature_path')->unique());
    }

    public function test_portal_vendor_cannot_induct_another_vendors_worker(): void
    {
        [$userA, $vendorA] = $this->vendorWithLogin('Alpha');
        [, $vendorB] = $this->vendorWithLogin('Bravo');
        $mine = $this->worker($vendorA, true, 'Mine');
        $theirs = $this->worker($vendorB, true, 'Theirs');

        Sanctum::actingAs($userA);
        $res = $this->postJson('/api/portal/workers/bulk-induction', $this->payload([$mine->id, $theirs->id]))
            ->assertOk();

        $this->assertSame([$mine->id], $res->json('saved'));
        $this->assertSame($theirs->id, $res->json('skipped.0.id'));
        // Existence-hiding: no name leaks for a worker the caller does not own.
        $this->assertNull($res->json('skipped.0.name'));
        $this->assertSame('Worker not found.', $res->json('skipped.0.reason'));
        $this->assertNull($theirs->fresh()->induction);
    }

    public function test_nothing_saved_leaves_no_orphan_signature(): void
    {
        [$user, $vendor] = $this->vendorWithLogin('Acme');
        $blocked = $this->worker($vendor, false);

        Sanctum::actingAs($user);
        $this->postJson('/api/portal/workers/bulk-induction', $this->payload([$blocked->id]))->assertOk();

        $this->assertCount(0, Storage::disk('public')->allFiles('workers/induction'));
    }
}
