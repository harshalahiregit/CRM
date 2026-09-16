<?php

namespace Tests\Feature\Auth;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Self-registration creates ONE record, and keeps everything the form asked for.
 *
 * It used to create two: the Purchase vendor, and a `users` row with role
 * `vendor`, status `pending` and no tenant — which existed only because
 * purchase_vendors had no columns for the contact fields. That row appeared on
 * no screen in the application (Staff Management lists staff and admins only),
 * belonged to no workspace, and could sign in nowhere. Three had accumulated on
 * the live workspace before anybody knew they were there.
 *
 * It also silently broke password resets. The reset searched logins first,
 * found the pending row, refused it as inactive, and never reached the vendor
 * account that could have sent a link — while still answering "if that email is
 * registered, a reset link has been sent".
 *
 * So the assertions that matter here are: no second row, and not one field lost
 * in removing it.
 */
class VendorSelfRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::query()->firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default', 'status' => 'active']
        );
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name'            => 'Ravi',
            'last_name'             => 'Menon',
            'email'                 => 'ravi@supplier.test',
            'company_name'          => 'Menon Industrial',
            'password'              => 'Str0ngPass!23',
            'password_confirmation' => 'Str0ngPass!23',
            'vendor_type'           => 'standard',
            'phone'                 => '9876543210',
            'designation'           => 'Director',
            'category'              => 'Fabrication',
            'website'               => 'https://menon.test',
            'address'               => '12 Works Road',
            'city'                  => 'Pune',
            'state'                 => 'Maharashtra',
            'country'               => 'India',
            'pincode'               => '411001',
            'company_phone'         => '02012345678',
            'manpower'              => '50-100',
            'msme'                  => 'UDYAM-MH-01-0001234',
        ], $overrides);
    }

    /** One supplier, one record. */
    public function test_registering_creates_no_hidden_login_row(): void
    {
        $this->postJson('/api/auth/register/vendor', $this->payload())->assertCreated();

        $this->assertSame(1, PurchaseVendor::where('email', 'ravi@supplier.test')->count());
        $this->assertSame(
            0,
            User::where('email', 'ravi@supplier.test')->count(),
            'a hidden login row was created alongside the vendor again'
        );
    }

    /** Every field the form collects survives onto the vendor. */
    public function test_the_contact_details_are_kept_on_the_vendor(): void
    {
        $this->postJson('/api/auth/register/vendor', $this->payload())->assertCreated();

        $v = PurchaseVendor::where('email', 'ravi@supplier.test')->firstOrFail();

        $this->assertSame('Ravi Menon', $v->contact_person, 'the contact name was lost with the user row');
        $this->assertSame('Director', $v->contact_designation);
        $this->assertSame('02012345678', $v->company_phone);
        $this->assertSame('50-100', $v->manpower);
        $this->assertSame('UDYAM-MH-01-0001234', $v->msme);

        // The switchboard and the contact's own line are different numbers.
        $this->assertSame('9876543210', $v->phone);
    }

    /** The password is stored on the vendor, hashed, so they can sign in later. */
    public function test_the_password_is_usable_and_hashed(): void
    {
        $this->postJson('/api/auth/register/vendor', $this->payload())->assertCreated();

        $v = PurchaseVendor::where('email', 'ravi@supplier.test')->firstOrFail();

        $this->assertNotSame('Str0ngPass!23', $v->password);
        $this->assertTrue(Hash::check('Str0ngPass!23', $v->password));
    }

    /** Registered, but shut out until an admin activates — the agreed flow. */
    public function test_the_portal_stays_shut_until_an_admin_activates(): void
    {
        $this->postJson('/api/auth/register/vendor', $this->payload())->assertCreated();

        $v = PurchaseVendor::where('email', 'ravi@supplier.test')->firstOrFail();

        $this->assertSame('Draft', $v->status);
        $this->assertNotSame('active', $v->portal_status);
    }

    /** It belongs to a workspace. The old hidden row belonged to none. */
    public function test_the_vendor_is_attached_to_a_tenant(): void
    {
        $this->postJson('/api/auth/register/vendor', $this->payload())->assertCreated();

        $this->assertNotNull(
            PurchaseVendor::where('email', 'ravi@supplier.test')->value('tenant_id'),
            'the vendor was created with no workspace'
        );
    }

    /**
     * The same address cannot register twice.
     *
     * The uniqueness rule used to point at `users`. With no user row written any
     * more, that guarded a table this flow never touches — so two suppliers
     * could take the same address and nothing at sign-in could tell them apart.
     */
    public function test_a_second_registration_on_the_same_email_is_refused(): void
    {
        $this->postJson('/api/auth/register/vendor', $this->payload())->assertCreated();

        $this->postJson('/api/auth/register/vendor', $this->payload(['company_name' => 'Someone Else']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /** A temporary registration keeps that choice rather than defaulting. */
    public function test_a_temporary_registration_is_recorded_as_temporary(): void
    {
        $this->postJson('/api/auth/register/vendor', $this->payload([
            'email' => 'temp@supplier.test', 'vendor_type' => 'temporary',
        ]))->assertCreated();

        $this->assertSame('temporary', PurchaseVendor::where('email', 'temp@supplier.test')->value('vendor_type'));
    }
}
