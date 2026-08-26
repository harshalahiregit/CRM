<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerMedical;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Tpv\TpvMedicalFitness as Fitness;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §3/§16 — the cross-workforce Medical Fitness register endpoint.
 */
class MedicalRegisterTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function medical(string $fitness, ?string $validUntil): void
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
        $w = TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'name' => 'W'.Str::random(3),
            'worker_code' => 'W-'.Str::random(5), 'current_step' => 1, 'status' => 'Draft',
        ]);
        TpvWorkerMedical::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $w->id,
            'fitness_status' => $fitness, 'exam_date' => now()->subMonth()->toDateString(), 'valid_until' => $validUntil,
        ]);
    }

    public function test_register_lists_records_with_summary(): void
    {
        $this->medical(Fitness::FIT, now()->addMonths(6)->toDateString());
        $this->medical(Fitness::UNFIT, null);
        $this->medical(Fitness::FIT, now()->subDay()->toDateString());   // expired

        Sanctum::actingAs($this->admin());
        $res = $this->getJson('/api/tpv/medical')->assertOk();

        $this->assertSame(3, $res->json('summary.total'));
        $this->assertSame(1, $res->json('summary.unfit'));
        $this->assertSame(1, $res->json('summary.expired'));
        $this->assertContains(Fitness::PENDING, $res->json('statuses'));
    }

    public function test_expiry_filter_narrows_to_expired(): void
    {
        $this->medical(Fitness::FIT, now()->addMonths(6)->toDateString());
        $this->medical(Fitness::FIT, now()->subDay()->toDateString());

        Sanctum::actingAs($this->admin());
        $res = $this->getJson('/api/tpv/medical?expiry=expired')->assertOk();

        $this->assertCount(1, $res->json('data'));
    }
}
