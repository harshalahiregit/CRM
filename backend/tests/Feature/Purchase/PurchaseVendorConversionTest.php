<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Purchase\PurchaseRegistrationType as RegistrationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Promoting a temporary Purchase Vendor to permanent.
 *
 * TPV has had this for as long as it has had temporary access. Purchase had the
 * temporary side complete — a registration type, an access window, an expiry
 * that shuts the portal — and no way out of it, so a temporary purchase vendor
 * could only ever expire.
 *
 * What made it worse than a missing feature: the vendor edit form offered
 * "Permanent" in its type dropdown, and picking it answered 200 while changing
 * nothing that mattered. isTemporary() reads registration_type first and the
 * update request never accepted that field, so vendor_type moved, the badge
 * flipped to Permanent, and the account went on expiring underneath. The screen
 * said the opposite of what the database held, and nothing anywhere reported it.
 */
class PurchaseVendorConversionTest extends TestCase
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

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function staff(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Staff', 'role' => 'staff',
            'email' => 's-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function temporaryVendor(array $overrides = []): PurchaseVendor
    {
        return PurchaseVendor::create(array_merge([
            'tenant_id' => self::TENANT,
            'company_name' => 'Acme Temporary',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'acme-'.Str::random(4).'@t.local',
            'category' => 'Supplier', 'currency' => 'INR',
            'vendor_type' => 'temporary',
            'registration_type' => RegistrationType::TEMPORARY,
            'status' => 'Active', 'portal_status' => 'active',
            'approved_at' => now()->subDays(10),
            'access_expires_at' => now()->addDays(5),
        ], $overrides));
    }

    /* ── the promotion ──────────────────────────────────────────── */

    public function test_an_admin_converts_a_temporary_vendor_to_permanent(): void
    {
        $vendor = $this->temporaryVendor();
        $this->assertTrue($vendor->isTemporary());

        Sanctum::actingAs($admin = $this->admin());

        $res = $this->postJson("/api/purchase/vendors/{$vendor->id}/convert");

        if ($res->getStatusCode() >= 400) {
            $this->fail('convert refused: '.$res->getStatusCode().' — '
                .json_encode($res->json('errors') ?? $res->json('message')));
        }

        $vendor->refresh();

        // registration_type is the one that decides. Moving vendor_type alone is
        // exactly the no-op this feature exists to replace.
        $this->assertSame(RegistrationType::STANDARD, $vendor->registration_type);
        $this->assertSame('standard', $vendor->vendor_type);
        $this->assertFalse($vendor->isTemporary(), 'the vendor is still temporary after being converted');

        // The expiry has to go, or EnsureTemporaryAccessNotExpired keeps matching.
        $this->assertNull($vendor->access_expires_at);

        // Who and when — the question an auditor asks.
        $this->assertNotNull($vendor->converted_to_permanent_at);
        $this->assertSame($admin->id, $vendor->converted_by);
    }

    public function test_the_conversion_is_recorded_against_the_vendor(): void
    {
        $vendor = $this->temporaryVendor();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/vendors/{$vendor->id}/convert")->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Purchase Vendor Converted to Permanent',
        ]);
    }

    public function test_a_closed_window_reopens_the_portal(): void
    {
        // The case that actually turns up: nobody converts until the vendor
        // rings to say they are locked out. Clearing the expiry while leaving
        // the login suspended would be the same complaint one step later.
        $vendor = $this->temporaryVendor([
            'access_expires_at' => now()->subDay(),
            'portal_status' => 'suspended',
        ]);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/purchase/vendors/{$vendor->id}/convert")->assertSuccessful();

        $this->assertSame('active', $vendor->fresh()->portal_status);
    }

    public function test_converting_twice_is_refused_rather_than_silently_repeated(): void
    {
        $vendor = $this->temporaryVendor();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/vendors/{$vendor->id}/convert")->assertSuccessful();
        $first = $vendor->fresh()->converted_to_permanent_at;

        $this->postJson("/api/purchase/vendors/{$vendor->id}/convert")->assertStatus(422);

        // A second attempt must not restamp the date — that would rewrite when
        // the promotion actually happened.
        $this->assertEquals($first, $vendor->fresh()->converted_to_permanent_at);
    }

    public function test_a_permanent_vendor_cannot_be_converted(): void
    {
        $vendor = $this->temporaryVendor([
            'vendor_type' => 'standard',
            'registration_type' => RegistrationType::STANDARD,
            'access_expires_at' => null,
        ]);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/purchase/vendors/{$vendor->id}/convert")->assertStatus(422);
    }

    public function test_staff_cannot_convert(): void
    {
        $vendor = $this->temporaryVendor();
        Sanctum::actingAs($this->staff());

        // Removing an expiry somebody set on purpose is admin authority, the
        // same as activation.
        $this->postJson("/api/purchase/vendors/{$vendor->id}/convert")->assertStatus(403);
        $this->assertTrue($vendor->fresh()->isTemporary());
    }

    /* ── the trap the edit form used to be ──────────────────────── */

    public function test_the_edit_form_can_no_longer_pretend_to_promote(): void
    {
        $vendor = $this->temporaryVendor();
        Sanctum::actingAs($this->admin());

        // Exactly what the form used to post. It answered 200 and did nothing.
        $res = $this->putJson("/api/purchase/vendors/{$vendor->id}", ['vendor_type' => 'standard']);

        $res->assertStatus(422);
        $this->assertStringContainsString('Convert to Permanent',
            (string) $res->json('message'), 'the refusal must name the action that does work');

        $vendor->refresh();
        $this->assertTrue($vendor->isTemporary());
        $this->assertNotNull($vendor->access_expires_at, 'the expiry must survive a refused edit');
    }

    public function test_an_ordinary_edit_still_saves(): void
    {
        $vendor = $this->temporaryVendor();
        Sanctum::actingAs($this->admin());

        // Sending the type UNCHANGED alongside a real edit is what the form does
        // on every save, so refusing that would break editing altogether.
        $this->putJson("/api/purchase/vendors/{$vendor->id}", [
            'company_name' => 'Acme Temporary Ltd',
            'vendor_type'  => 'temporary',
            'phone'        => '+91 99999 00000',
        ])->assertSuccessful();

        $vendor->refresh();
        $this->assertSame('Acme Temporary Ltd', $vendor->company_name);
        $this->assertTrue($vendor->isTemporary());
    }

    public function test_a_permanent_vendor_cannot_be_demoted_by_editing(): void
    {
        $vendor = $this->temporaryVendor([
            'vendor_type' => 'standard',
            'registration_type' => RegistrationType::STANDARD,
            'access_expires_at' => null,
        ]);

        Sanctum::actingAs($this->admin());
        $this->putJson("/api/purchase/vendors/{$vendor->id}", ['vendor_type' => 'temporary'])
            ->assertStatus(422);

        $this->assertFalse($vendor->fresh()->isTemporary());
    }
}
