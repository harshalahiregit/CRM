<?php

namespace Tests\Feature\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\Tpv\TpvInspection;
use App\Models\Tpv\TpvInspectionFinding;
use App\Services\Tpv\TpvInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rule 11 (§36) — an inspection finding needs a named owner before it can be
 * actioned or closed, mirroring the CAPA/NCR owner gates.
 */
class InspectionFindingOwnerGateTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function finding(): TpvInspectionFinding
    {
        $insp = TpvInspection::create([
            'tenant_id' => self::TENANT, 'reference' => 'INSP-'.uniqid(),
            'title' => 'Site walkdown', 'type' => 'Safety', 'status' => 'Completed',
        ]);

        return $insp->findings()->create([
            'tenant_id' => self::TENANT, 'description' => 'Guard missing',
            'category' => 'Non_Conformance', 'severity' => 'Major', 'status' => 'Open',
        ]);
    }

    public function test_closing_without_an_owner_is_refused(): void
    {
        $finding = $this->finding();

        $this->expectException(BusinessException::class);
        app(TpvInspectionService::class)->updateFinding($finding, ['status' => 'Closed']);
    }

    public function test_closing_with_an_owner_succeeds(): void
    {
        $finding = $this->finding();

        $updated = app(TpvInspectionService::class)->updateFinding($finding, [
            'status' => 'Action', 'responsible_by' => 42,
        ]);

        $this->assertSame('Action', $updated->status);
        $this->assertSame(42, (int) $updated->responsible_by);
    }
}
