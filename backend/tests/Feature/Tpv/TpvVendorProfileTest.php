<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TPV vendor profile — registration capture, admin editing, and the backfill.
 *
 * The defect these pin down: the registration form collected website, industry,
 * legal name, PAN and address, wrote them to users.meta, and never copied them to
 * the vendor. The Profile tab reads the VENDOR, so it showed a dash for details
 * the vendor had already supplied.
 */
class TpvVendorProfileTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT, 999] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => 'T'.$id, 'slug' => 't'.$id,
                'subdomain' => 't'.$id, 'status' => 'active',
            ])->save();
        }
    }

    private function user(string $role, int $tenant = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenant, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** Register a TPV through the real service, with the full form payload. */
    private function registerTpv(array $overrides = []): User
    {
        return app(AuthService::class)->registerTPV(array_merge([
            'first_name' => 'Reg', 'last_name' => 'Vendor',
            'email'      => 'reg-'.Str::random(6).'@test.local',
            'password'   => 'Secret@12345',
            'username'   => 'Forever Architect',
            'tpv_type'   => 'permanent',
            'phone'      => '9876543210',
            'industry'   => 'Architecture',
            'website'    => 'https://forever.test',
            'legal_name' => 'Forever Architect Private Limited',
            'pan_number' => 'aabcu9603r',
            'address'    => 'Unit 4, MIDC, Andheri',
            'gst_number' => '27AABCU9603R1ZN',
            'city'       => 'Mumbai', 'state' => 'Maharashtra',
            'country'    => 'India', 'zip' => '400093',
        ], $overrides));
    }

    /* ── 1–5: registration writes the vendor record ─────────────────── */

    public function test_registration_saves_website_to_the_vendor(): void
    {
        $vendor = Vendor::where('user_id', $this->registerTpv()->id)->firstOrFail();

        $this->assertSame('https://forever.test', $vendor->website);
    }

    /** The form says "industry"; the vendor master calls it "category". */
    public function test_registration_maps_industry_to_category(): void
    {
        $vendor = Vendor::where('user_id', $this->registerTpv()->id)->firstOrFail();

        $this->assertSame('Architecture', $vendor->category);
    }

    public function test_registration_saves_legal_name(): void
    {
        $vendor = Vendor::where('user_id', $this->registerTpv()->id)->firstOrFail();

        $this->assertSame('Forever Architect Private Limited', $vendor->legal_name);
    }

    /** PAN is normalised to upper case on the way in. */
    public function test_registration_saves_pan_uppercased(): void
    {
        $vendor = Vendor::where('user_id', $this->registerTpv()->id)->firstOrFail();

        $this->assertSame('AABCU9603R', $vendor->pan_number);
    }

    public function test_registration_saves_address(): void
    {
        $vendor = Vendor::where('user_id', $this->registerTpv()->id)->firstOrFail();

        $this->assertSame('Unit 4, MIDC, Andheri', $vendor->address);
    }

    /** The fields stay optional — a partial registration must still succeed. */
    public function test_registration_still_works_without_the_new_fields(): void
    {
        $user = $this->registerTpv([
            'legal_name' => null, 'pan_number' => null, 'address' => null,
            'website' => null, 'industry' => null,
        ]);

        $vendor = Vendor::where('user_id', $user->id)->firstOrFail();

        $this->assertNull($vendor->legal_name);
        $this->assertNull($vendor->website);
    }

    /* ── 6–7: who may edit the profile ──────────────────────────────── */

    private function vendorRow(array $attrs = []): Vendor
    {
        return Vendor::create(array_merge([
            'tenant_id' => self::TENANT, 'company_name' => 'EditCo',
            'email' => 'edit-'.Str::random(4).'@test.local', 'status' => 'Active',
        ], $attrs));
    }

    public function test_admin_and_staff_can_update_the_vendor_profile(): void
    {
        foreach (['admin', 'staff'] as $role) {
            $vendor = $this->vendorRow();
            Sanctum::actingAs($this->user($role));

            $this->putJson("/api/vendors/{$vendor->id}", [
                'legal_name' => 'Edited Legal '.$role,
                'category'   => 'Consulting',
                'website'    => 'https://edited.test',
                'pan_number' => 'AABCU9603R',
                'address'    => 'New address',
            ])->assertOk();

            $this->assertSame('Edited Legal '.$role, $vendor->fresh()->legal_name);
        }
    }

    /** The UI hides the button; this is the boundary that actually holds. */
    public function test_vendor_roles_cannot_update_a_vendor_profile(): void
    {
        $vendor = $this->vendorRow(['legal_name' => 'Untouched']);

        foreach (['third_party_vendor', 'vendor'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->putJson("/api/vendors/{$vendor->id}", ['legal_name' => 'Hacked'])
                ->assertForbidden();
        }

        $this->assertSame('Untouched', $vendor->fresh()->legal_name);
    }

    /** 404, not 403 — the module's existing existence-hiding convention. */
    public function test_staff_cannot_update_another_tenants_vendor(): void
    {
        $foreign = $this->vendorRow(['tenant_id' => 999, 'legal_name' => 'Foreign']);

        Sanctum::actingAs($this->user('staff'));

        $this->putJson("/api/vendors/{$foreign->id}", ['legal_name' => 'Hacked'])
            ->assertNotFound();

        $this->assertSame('Foreign', $foreign->fresh()->legal_name);
    }

    /* ── 8–9: the backfill ──────────────────────────────────────────── */

    /** Runs the shipped migration rather than a copy of its logic. */
    private function runBackfill(): void
    {
        (include database_path('migrations/2026_10_10_000001_backfill_tpv_vendor_profile_from_user_meta.php'))->up();
    }

    public function test_backfill_populates_empty_vendor_fields_from_user_meta(): void
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Legacy', 'role' => 'third_party_vendor',
            'email' => 'legacy-'.Str::random(4).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
            'meta' => [
                'website' => 'https://legacy.test', 'industry' => 'Fabrication',
                'legal_name' => 'Legacy Pvt Ltd', 'pan_number' => 'aabcu9603r',
                'address' => 'Old Road',
            ],
        ]);

        // The row as registration used to leave it: identity fields blank.
        $vendor = $this->vendorRow(['user_id' => $user->id, 'website' => null, 'category' => null]);

        $this->runBackfill();
        $vendor->refresh();

        $this->assertSame('https://legacy.test', $vendor->website);
        $this->assertSame('Fabrication', $vendor->category);
        $this->assertSame('Legacy Pvt Ltd', $vendor->legal_name);
        $this->assertSame('AABCU9603R', $vendor->pan_number, 'PAN is normalised on backfill too.');
        $this->assertSame('Old Road', $vendor->address);
    }

    public function test_backfill_never_overwrites_an_existing_value(): void
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Curated', 'role' => 'third_party_vendor',
            'email' => 'curated-'.Str::random(4).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
            'meta' => ['website' => 'https://from-meta.test', 'industry' => 'FromMeta'],
        ]);

        $vendor = $this->vendorRow([
            'user_id'  => $user->id,
            'website'  => 'https://curated-by-admin.test',
            'category' => 'CuratedByAdmin',
        ]);

        $this->runBackfill();
        $vendor->refresh();

        $this->assertSame('https://curated-by-admin.test', $vendor->website);
        $this->assertSame('CuratedByAdmin', $vendor->category);
    }

    /** Safe to run twice — it is a migration, and migrations get re-run in CI. */
    public function test_backfill_is_idempotent(): void
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Twice', 'role' => 'third_party_vendor',
            'email' => 'twice-'.Str::random(4).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
            'meta' => ['website' => 'https://twice.test'],
        ]);
        $vendor = $this->vendorRow(['user_id' => $user->id, 'website' => null]);

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame('https://twice.test', $vendor->fresh()->website);
    }

    /** A vendor with no portal login must not break the sweep. */
    public function test_backfill_skips_vendors_without_a_user(): void
    {
        $orphan = $this->vendorRow(['user_id' => null, 'website' => null]);

        $this->runBackfill();

        $this->assertNull($orphan->fresh()->website);
    }
}
