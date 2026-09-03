<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P0-4 — legal capture on the medical sign: the server stamps the IP, and the
 * client-sent geolocation + photo are persisted alongside the signature.
 */
class MedicalLegalCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function worker(): TpvWorker
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);

        return TpvWorker::create(['tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'name' => 'Ravi', 'current_step' => 1, 'status' => 'Draft']);
    }

    public function test_ip_geo_and_photo_are_captured_on_the_medical_sign(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'Dr', 'role' => 'admin',
            'email' => 'd-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]));
        $worker = $this->worker();

        $this->postJson("/api/tpv/workers/{$worker->id}/medical", [
            'exam_type'      => 'internal',
            'examiner_name'  => 'Dr Meera',
            'fitness_status' => 'Fit',
            'geo_location'   => '12.340000,56.780000',
            'capture_photo'  => 'data:image/png;base64,ZmFrZWltZw==',
        ])->assertOk();

        $med = $worker->fresh('medical')->medical;
        $this->assertNotEmpty($med->system_ip, 'the server should stamp the caller IP');
        $this->assertSame('12.340000,56.780000', $med->geo_location);
        $this->assertNotNull($med->capture_photo_path);
        Storage::disk('public')->assertExists($med->capture_photo_path);
    }
}
