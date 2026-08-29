<?php

namespace Tests\Feature\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseIncidentService;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Purchase HSSE incident engine (parity with TPV IncidentService): report → RCA →
 * CAPA → close. Two governance rules are asserted here — a Serious/Fatal or
 * stop-work incident auto-suspends (On_Hold) the vendor, and an incident cannot
 * close until its RCA is recorded and every linked CAPA is Verified.
 */
class PurchaseIncidentEngineTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function service(): PurchaseIncidentService
    {
        return app(PurchaseIncidentService::class);
    }

    private function actor(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'HSSE', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(string $status = PurchaseVendorStatus::ACTIVE): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme',
            'email' => 'v-'.Str::random(4).'@pv.local',
            'purchase_vendor_code' => 'PUR-'.Str::random(6), 'status' => $status,
        ]);
    }

    private function report(PurchaseVendor $v, User $actor, array $overrides = []): \App\Models\Purchase\PurchaseHsseIncident
    {
        return $this->service()->create(self::TENANT, array_merge([
            'purchase_vendor_id' => $v->id,
            'title'              => 'Slip near loading bay',
            'type'               => 'Injury',
            'severity'           => 'Minor',
        ], $overrides), $actor);
    }

    public function test_reporting_an_incident_creates_it_with_a_reference(): void
    {
        $incident = $this->report($this->vendor(), $this->actor());

        $this->assertNotNull($incident->id);
        $this->assertStringStartsWith('PINC-', $incident->reference);
        $this->assertSame('Reported', $incident->status);
        $this->assertFalse((bool) $incident->triggered_suspension);
    }

    public function test_grave_incident_auto_suspends_the_vendor(): void
    {
        $actor = $this->actor();
        $v = $this->vendor();

        $incident = $this->report($v, $actor, ['severity' => 'Serious']);

        $this->assertTrue((bool) $incident->triggered_suspension);
        $this->assertSame(PurchaseVendorStatus::ON_HOLD, $v->fresh()->status);
    }

    public function test_stop_work_incident_suspends_even_when_minor(): void
    {
        $actor = $this->actor();
        $v = $this->vendor();

        $incident = $this->report($v, $actor, ['severity' => 'Minor', 'stop_work' => true]);

        $this->assertTrue((bool) $incident->triggered_suspension);
        $this->assertSame(PurchaseVendorStatus::ON_HOLD, $v->fresh()->status);
    }

    public function test_minor_incident_leaves_vendor_active(): void
    {
        $actor = $this->actor();
        $v = $this->vendor();

        $this->report($v, $actor, ['severity' => 'Minor']);

        $this->assertSame(PurchaseVendorStatus::ACTIVE, $v->fresh()->status);
    }

    public function test_close_is_blocked_without_a_recorded_rca(): void
    {
        $actor = $this->actor();
        $incident = $this->report($this->vendor(), $actor);

        $this->expectException(BusinessException::class);
        $this->service()->close($incident, $actor);
    }

    public function test_close_is_blocked_while_a_capa_is_unverified(): void
    {
        $actor = $this->actor();
        $incident = $this->report($this->vendor(), $actor);

        $this->service()->recordRca($incident, ['root_cause' => 'No handrail'], $actor);
        $this->service()->addCapa($incident->fresh(), ['description' => 'Install handrail'], $actor);

        $this->expectException(BusinessException::class);
        $this->service()->close($incident->fresh(), $actor);
    }

    public function test_close_succeeds_after_rca_and_all_capas_verified(): void
    {
        $actor = $this->actor();
        $incident = $this->report($this->vendor(), $actor);

        $this->service()->recordRca($incident, ['root_cause' => 'No handrail'], $actor);
        $capa = $this->service()->addCapa($incident->fresh(), ['description' => 'Install handrail'], $actor);

        // Verifying is gated on closure evidence (Rule 12).
        $this->service()->updateCapa($capa, ['status' => 'Verified', 'evidence_path' => 'proof/handrail.pdf'], $actor);

        $closed = $this->service()->close($incident->fresh(), $actor);

        $this->assertSame('Closed', $closed->status);
        $this->assertNotNull($closed->closed_at);
    }
}
