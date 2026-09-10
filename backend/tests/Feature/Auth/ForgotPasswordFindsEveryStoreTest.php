<?php

namespace Tests\Feature\Auth;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseVendorPortalAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * One reset form for three identity stores.
 *
 * There used to be three forms, chosen by the login screen's role dropdown —
 * and that dropdown is optional. Anybody who clicked "Forgot password" without
 * touching it landed on the one that resets staff accounts only. A supplier or
 * a customer contact was then told "if that email is registered, a reset link
 * has been sent" and received nothing, with no way to learn they had used the
 * wrong door. That is exactly how a real address went unanswered: it existed as
 * a Purchase vendor, the staff-account search found a hidden pending row
 * instead, refused it, and stopped.
 *
 * So: given a role, search that store alone — the screen already knows, and
 * saying so keeps it cheap and debuggable. Given none, search every store and
 * send one link per account found. An address can legitimately be two accounts,
 * and sending only the first leaves the other permanently unreachable.
 *
 * The reply never varies. It cannot be used to discover which addresses exist.
 */
class ForgotPasswordFindsEveryStoreTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'reset-stores', 'status' => 'active']);
        $this->tenantId = $tenant->id;
    }

    private function vendor(string $email): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => $this->tenantId, 'purchase_vendor_code' => 'PV-'.random_int(1000, 9999),
            'company_name' => 'Acme', 'email' => $email, 'vendor_type' => 'standard',
            'status' => 'Active', 'portal_status' => 'active', 'password' => Hash::make('x'),
        ]);
    }

    private function staff(string $email): User
    {
        return User::create([
            'tenant_id' => $this->tenantId, 'name' => 'Sam', 'email' => $email,
            'password' => Hash::make('x'), 'role' => 'staff', 'status' => 'active',
        ]);
    }

    /** A supplier's address, with no role picked, still gets a link. */
    public function test_a_purchase_vendor_is_found_when_no_role_is_selected(): void
    {
        $this->vendor('supplier@stores.test');

        $spy = Mockery::mock(PurchaseVendorPortalAuthService::class);
        $spy->shouldReceive('forgotPassword')->once()->with('supplier@stores.test');
        $this->app->instance(PurchaseVendorPortalAuthService::class, $spy);

        $this->postJson('/api/auth/forgot-password', ['email' => 'supplier@stores.test'])
            ->assertOk();
    }

    /** Naming the role searches that store and no other. */
    public function test_naming_purchase_vendor_searches_only_that_store(): void
    {
        $this->staff('both@stores.test');
        $this->vendor('both@stores.test');

        $spy = Mockery::mock(PurchaseVendorPortalAuthService::class);
        $spy->shouldReceive('forgotPassword')->once();
        $this->app->instance(PurchaseVendorPortalAuthService::class, $spy);

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'both@stores.test', 'role' => 'purchase_vendor',
        ])->assertOk();

        // The staff account was not touched, so no token exists for it.
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'both@stores.test']);
    }

    /** A staff role skips the vendor store entirely. */
    public function test_naming_a_staff_role_does_not_touch_the_vendor_store(): void
    {
        $this->staff('sam@stores.test');

        $spy = Mockery::mock(PurchaseVendorPortalAuthService::class);
        $spy->shouldNotReceive('forgotPassword');
        $this->app->instance(PurchaseVendorPortalAuthService::class, $spy);

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'sam@stores.test', 'role' => 'staff',
        ])->assertOk();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'sam@stores.test']);
    }

    /** One address, two accounts, no role: both are contacted. */
    public function test_an_address_held_twice_gets_a_link_for_each_account(): void
    {
        $this->staff('double@stores.test');
        $this->vendor('double@stores.test');

        $spy = Mockery::mock(PurchaseVendorPortalAuthService::class);
        $spy->shouldReceive('forgotPassword')->once()->with('double@stores.test');
        $this->app->instance(PurchaseVendorPortalAuthService::class, $spy);

        $this->postJson('/api/auth/forgot-password', ['email' => 'double@stores.test'])
            ->assertOk();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'double@stores.test']);
    }

    /** An unknown address is answered exactly the same way. */
    public function test_an_unknown_address_is_indistinguishable(): void
    {
        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@stores.test']);
        $this->staff('someone@stores.test');
        $other = $this->postJson('/api/auth/forgot-password', ['email' => 'someone@stores.test']);

        $this->assertSame($known->json('message'), $other->json('message'));
        $this->assertSame($known->status(), $other->status());
    }

    /**
     * One store throwing must not silence the others.
     *
     * These run before the staff branch, so an exception there would take the
     * whole request down and nobody would get anything.
     */
    public function test_a_failure_in_one_store_still_lets_the_others_send(): void
    {
        $this->staff('resilient@stores.test');

        $spy = Mockery::mock(PurchaseVendorPortalAuthService::class);
        $spy->shouldReceive('forgotPassword')->andThrow(new \RuntimeException('smtp down'));
        $this->app->instance(PurchaseVendorPortalAuthService::class, $spy);

        $this->postJson('/api/auth/forgot-password', ['email' => 'resilient@stores.test'])
            ->assertOk();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'resilient@stores.test']);
    }
}
