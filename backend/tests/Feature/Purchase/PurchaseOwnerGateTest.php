<?php

namespace Tests\Feature\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseNcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rule 11 — "Every Action Has an Owner" on the PURCHASE side (parity with
 * ActionOwnerRuleTest / InspectionFindingOwnerGateTest). A Purchase NCR cannot be
 * progressed past "Raised" while it has no responsible owner.
 */
class PurchaseOwnerGateTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function actor(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    public function test_ncr_cannot_progress_without_a_responsible_owner(): void
    {
        $actor = $this->actor();
        $ncr = app(PurchaseNcrService::class)->create(
            ['title' => 'Spec deviation', 'finding' => 'x', 'severity' => 'Major'],
            self::TENANT, $actor->id
        ); // responsible_by is null

        $this->expectException(BusinessException::class);
        app(PurchaseNcrService::class)->transition($ncr, 'Assigned', $actor);
    }

    public function test_ncr_progresses_once_an_owner_is_set(): void
    {
        $actor = $this->actor();
        $ncr = app(PurchaseNcrService::class)->create(
            ['title' => 'Spec deviation', 'finding' => 'y', 'severity' => 'Major'],
            self::TENANT, $actor->id
        );
        $ncr->update(['responsible_by' => $actor->id]);

        $updated = app(PurchaseNcrService::class)->transition($ncr, 'Assigned', $actor);
        $this->assertSame('Assigned', $updated->status);
    }
}
