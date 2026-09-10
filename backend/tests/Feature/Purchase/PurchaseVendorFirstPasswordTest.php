<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseVendorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The first password on an admin-created Purchase vendor.
 *
 * An admin may type one or leave it blank, and the vendor must be told which it
 * is either way: the welcome e-mail is the ONLY place that password ever exists
 * in readable form. Only the hash is stored, so no screen can show it
 * afterwards and no amount of support can recover it — a vendor who never got
 * that mail has no route into the portal at all.
 *
 * The trap being guarded here is quieter. `password` is fillable and the model
 * has NO hashing cast, so a typed password left in the create() payload is
 * written to the column verbatim. Nothing complains: the row saves, the admin
 * sees success, and the account is simply unusable — every login hashes what it
 * is given before comparing, and a hash never equals the plain text beside it.
 */
class PurchaseVendorFirstPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'pv-password', 'status' => 'active']);

        $this->admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin@pv.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function create(array $extra = []): PurchaseVendor
    {
        return app(PurchaseVendorService::class)->create(array_merge([
            'company_name' => 'Acme Supplies',
            'vendor_type'  => 'standard',
            'category'     => 'Materials',
            'currency'     => 'INR',
            'email'        => 'buyer@acme.test',
        ], $extra), $this->admin);
    }

    /** A typed password is stored as a HASH, and the vendor can sign in with it. */
    public function test_an_admin_chosen_password_is_hashed_not_stored_verbatim(): void
    {
        $vendor = $this->create(['password' => 'ChosenPass123']);

        $this->assertNotSame('ChosenPass123', $vendor->password, 'the password was stored in plain text');
        $this->assertTrue(Hash::check('ChosenPass123', $vendor->password), 'the stored hash does not match the typed password');
    }

    /** Left blank, the system mints one — the account is never left without. */
    public function test_a_blank_password_still_produces_a_usable_account(): void
    {
        $vendor = $this->create();

        $this->assertNotNull($vendor->password, 'no password was set at all');
        $this->assertSame('active', $vendor->portal_status, 'the portal was never opened');
    }

    /** Typing one opens the portal too — not only the generated path. */
    public function test_a_chosen_password_also_opens_the_portal(): void
    {
        $vendor = $this->create(['password' => 'ChosenPass123']);

        $this->assertSame('active', $vendor->portal_status);
    }

    /**
     * A vendor with no e-mail gets no credentials mail, and must not be left
     * holding a password nobody can ever tell them.
     */
    public function test_a_vendor_without_an_email_is_not_silently_given_a_secret(): void
    {
        $vendor = $this->create(['email' => null]);

        $this->assertNull($vendor->password, 'a password was minted that could never be delivered');
    }
}
