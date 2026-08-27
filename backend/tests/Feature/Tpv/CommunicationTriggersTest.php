<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerMedical;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvCommunicationService;
use App\Support\Tpv\TpvMedicalFitness;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §31 Communications — the trigger catalogue is complete, and the derived alerts
 * feed now surfaces the doc's expiry/strike/suspension/approval triggers.
 */
class CommunicationTriggersTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_trigger_catalogue_covers_the_doc(): void
    {
        foreach (['approval', 'training_expiry', 'medical_expiry', 'contract_expiry', 'permit_expiry',
                  'meeting_invitation', 'mom_distribution', 'action_reminder', 'strike', 'suspension'] as $t) {
            $this->assertContains($t, TpvCommunicationService::TRIGGERS, "Trigger '{$t}' must be catalogued (§31).");
        }
    }

    public function test_medical_expiry_produces_an_alert(): void
    {
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
        $worker = TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'name' => 'Ravi',
            'worker_code' => 'W-'.Str::random(5), 'current_step' => 1, 'status' => 'Draft',
        ]);
        TpvWorkerMedical::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $worker->id,
            'fitness_status' => TpvMedicalFitness::FIT, 'valid_until' => now()->subDay()->toDateString(),
        ]);

        $alerts = app(TpvCommunicationService::class)->alerts(self::TENANT);
        $kinds = collect($alerts)->pluck('kind');

        $this->assertTrue($kinds->contains('medical_expiry'), 'An expired medical must raise a medical_expiry alert.');
    }

    public function test_suspended_vendor_produces_an_alert(): void
    {
        Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'SusCo', 'status' => 'Suspended']);

        $alerts = app(TpvCommunicationService::class)->alerts(self::TENANT);
        $this->assertTrue(collect($alerts)->pluck('kind')->contains('suspension'));
    }
}
