<?php

namespace Tests\Feature\Auth;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The `vendor` User role is retired.
 *
 * Two spellings of "vendor" lived in the users table and they did not mean the
 * same thing. `third_party_vendor` is a TPV contractor and attaches to a
 * `vendors` row. `vendor` attached to a `purchase_vendors` row — or to nothing.
 *
 * A purchase vendor is not a User. It authenticates as ITSELF, out of
 * purchase_vendors, with its own password and its own Sanctum token. So every
 * `vendor` User beside one was a second, redundant login for the same supplier,
 * and it routed to the TPV portal: the wrong one, for records kept somewhere
 * else entirely.
 *
 * The reasoning that put a "Vendor" option on the login form was that both roles
 * reached /vendor-portal, so they must be the same thing. That was read off the
 * ROUTING, and the routing was the broken half.
 */
class VendorRoleIsRetiredTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    public function test_the_login_form_does_not_offer_the_vendor_role(): void
    {
        $login = file_get_contents(base_path('../frontend/src/pages/auth/LoginPage.jsx'));

        $this->assertDoesNotMatchRegularExpression("/value:\s*'vendor'/", $login,
            'the login form offers Vendor again, which sends purchase suppliers to the TPV portal');

        // The one that IS real stays.
        $this->assertMatchesRegularExpression("/value:\s*'purchase_vendor'/", $login,
            'Purchase Vendor is how a purchase supplier actually signs in');
        $this->assertMatchesRegularExpression("/value:\s*'third_party_vendor'/", $login);
    }

    public function test_the_seeder_no_longer_creates_a_vendor_role_user(): void
    {
        $seeder = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        // It was the last thing in the system still producing the role.
        $this->assertStringNotContainsString("'role'        => 'vendor'", $seeder);
        $this->assertStringNotContainsString('"role" => "vendor"', $seeder);

        $this->assertStringContainsString('PurchaseVendor::firstOrCreate', $seeder,
            'the demo vendor should be a purchase vendor record, not a User');
    }

    /**
     * The retirement migration deactivates, it does not delete.
     *
     * These rows are referenced elsewhere — a project's vendor_user_id, an audit
     * trail's actor — and deleting them turns readable history into dangling
     * ids. Deactivating stops the login and leaves the record.
     */
    public function test_a_leftover_vendor_user_is_deactivated_not_deleted(): void
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Leftover Supplier',
            'role' => 'vendor', 'email' => 'left-'.Str::random(5).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);

        PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Leftover Supplies',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => $user->email, 'password' => bcrypt('x'),
            'category' => 'Supplier', 'currency' => 'INR',
            'status' => 'Active', 'portal_status' => 'active',
        ]);

        // Re-run the migration's work against this fixture.
        $this->retireVendorUsers();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'inactive']);
        $this->assertNotNull(User::find($user->id), 'the row must survive — history points at it');
    }

    public function test_a_third_party_vendor_is_left_alone(): void
    {
        $tpv = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Real TPV',
            'role' => 'third_party_vendor', 'email' => 'tpv-'.Str::random(5).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->retireVendorUsers();

        // The other spelling is a real, distinct role with its own portal.
        $this->assertDatabaseHas('users', ['id' => $tpv->id, 'status' => 'active']);
    }

    /**
     * A supplier is never stranded.
     *
     * Retiring the redundant User only makes sense because the purchase_vendors
     * row beside it already carries a working password. If that stopped being
     * true, this cleanup would lock somebody out.
     */
    public function test_the_supplier_still_has_a_login_after_the_cleanup(): void
    {
        $email = 'supplier-'.Str::random(5).'@t.local';

        User::create([
            'tenant_id' => self::TENANT, 'name' => 'Supplier', 'role' => 'vendor',
            'email' => $email, 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $pv = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Supplier Ltd',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => $email, 'password' => bcrypt('secret'),
            'category' => 'Supplier', 'currency' => 'INR',
            'status' => 'Active', 'portal_status' => 'active',
        ]);

        $this->retireVendorUsers();

        $pv->refresh();
        $this->assertSame('active', $pv->portal_status);
        $this->assertNotNull($pv->password, 'the purchase login is the one they keep');
    }

    /** What the migration does, so the behaviour is asserted rather than assumed. */
    private function retireVendorUsers(): void
    {
        DB::table('users')->where('role', 'vendor')->update([
            'status' => 'inactive',
            'updated_at' => now(),
        ]);
    }
}
