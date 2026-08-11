<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pins the two defects that made a Temporary TPV's countdown banner invisible.
 * Both were silent: the frontend swallows the countdown 404 with `.catch(() => {})`
 * and the banner renders `null` when is_temporary is falsey, so neither surfaced
 * as an error anywhere — the banner simply never appeared.
 *
 * Bug A — registerTPV() stamped tenant_id on the Vendor row but not on the User,
 *   so Vendor::forTenant($user->tenant_id) resolved nothing and
 *   GET /tpv/access/countdown 404'd for every self-registered TPV.
 *
 * Bug B — the portal payload exposed the raw `is_temporary` COLUMN, which the
 *   registration paths never write. It read false for genuine temporary vendors,
 *   so once portalApi.me() resolved, the banner hid itself even when the
 *   countdown endpoint was healthy. isTemporary() reads registration_type.
 *
 * They are independent: either one alone hides the banner, so both are asserted.
 */
class TemporaryAccessCountdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // AgencyContext resolves the tenant these external parties are bound to;
        // on an empty test database there is nothing for it to find and the users
        // FK rejects the insert. forceCreate because `id` is not fillable.
        (new Tenant())->forceFill([
            'id' => 1, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();
    }

    private function registerTpv(string $type): User
    {
        return app(AuthService::class)->registerTPV([
            'first_name' => 'Reg',
            'last_name'  => ucfirst($type),
            'email'      => $type.'-'.uniqid().'@example.test',
            'password'   => 'Secret@12345',
            'username'   => 'Reg '.$type.' Co',
            'tpv_type'   => $type,
            'phone'      => '9990001111',
            'position'   => 'Manager',
        ]);
    }

    /** Bug A: the User must carry the same tenant as the Vendor created with it. */
    public function test_self_registered_tpv_user_gets_the_vendor_tenant(): void
    {
        $user   = $this->registerTpv('temporary');
        $vendor = Vendor::where('user_id', $user->id)->firstOrFail();

        $this->assertNotNull($user->tenant_id, 'users.tenant_id must not be NULL — the countdown lookup scopes by it.');
        $this->assertSame((int) $vendor->tenant_id, (int) $user->tenant_id);

        // The exact lookup the countdown endpoint performs.
        $this->assertNotNull(
            Vendor::forTenant($user->tenant_id)->where('user_id', $user->id)->first(),
            'Vendor::forTenant($user->tenant_id) must resolve, or the countdown 404s.'
        );
    }

    /** Bug A, at the endpoint: the symptom users actually hit. */
    public function test_countdown_endpoint_resolves_for_a_temporary_tpv(): void
    {
        $user = $this->registerTpv('temporary');
        Vendor::where('user_id', $user->id)->update([
            'access_expires_at' => now()->addDays(5),
            'access_status'     => 'Active',
        ]);

        Sanctum::actingAs($user->fresh());

        $res = $this->getJson('/api/tpv/access/countdown')->assertOk();

        $this->assertTrue($res->json('is_temporary'), 'A temporary TPV must be reported as temporary.');
        $this->assertGreaterThan(4 * 86400, $res->json('seconds_remaining'), 'A fresh 5-day window has >4 days left.');
        $this->assertSame('green', $res->json('band'));
    }

    /**
     * Bug B: the raw column is false on rows the registration paths write, so a
     * payload that mirrors it lies. This asserts the computed value is what ships.
     */
    public function test_temporary_is_computed_not_read_from_the_stale_column(): void
    {
        $user   = $this->registerTpv('temporary');
        $vendor = Vendor::where('user_id', $user->id)->firstOrFail();

        // The precondition that made the old payload wrong.
        $this->assertFalse((bool) $vendor->getRawOriginal('is_temporary'), 'Precondition: the raw column is unset.');
        $this->assertTrue($vendor->isTemporary(), 'registration_type is the source of truth.');

        // The payload itself — this is the assertion that guards the fix. Asserting
        // isTemporary() alone would pass even with the controller reverted, because
        // the model method was never the broken part.
        $vendor->update(['access_expires_at' => now()->addDays(5), 'access_status' => 'Active']);
        Sanctum::actingAs($user->fresh());

        $res = $this->getJson('/api/portal/me')->assertOk();

        $payload = $res->json('vendor') ?? $res->json('data.vendor');
        $this->assertNotNull($payload, 'portal/me must return a vendor object.');
        $this->assertTrue(
            (bool) $payload['is_temporary'],
            'portal/me must ship the COMPUTED is_temporary — the banner keys on it and hides when falsey.'
        );
    }

    /** A permanent TPV must never be reported as temporary — the banner keys on this. */
    public function test_permanent_tpv_is_not_temporary(): void
    {
        $user   = $this->registerTpv('permanent');
        $vendor = Vendor::where('user_id', $user->id)->firstOrFail();

        $this->assertFalse($vendor->isTemporary());

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/tpv/access/countdown')
            ->assertOk()
            ->assertJsonPath('is_temporary', false);
    }
}
