<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Contracts\DriverDirectory;
use App\Domains\Fleet\Directory\CrmDriverDirectory;
use App\Domains\Fleet\Directory\StandaloneDriverDirectory;
use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Services\DriverService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Transport must run BOTH ways: as a CRM module reading the shared customer
 * directory, and as its own application with no CRM around it.
 *
 * The test that matters most: add people under a vendor in the CRM and they
 * appear in Transport with nobody re-entering them, and with no copy of their
 * name stored on our side to go stale.
 */
class DriverDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;
    private const OTHER   = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::COMPANY, self::OTHER] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => "Co{$id}", 'slug' => "co{$id}",
                'subdomain' => "co{$id}", 'status' => 'active',
            ])->save();
        }
    }

    private function user(string $role = 'staff', int $company = self::COMPANY): User
    {
        return User::create([
            'tenant_id' => $company, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** A vendor with N workers under it, exactly as the CRM would hold them. */
    private function vendorWithWorkers(int $count, int $company = self::COMPANY, string $designation = 'Driver'): int
    {
        $vendorId = DB::table('vendors')->insertGetId([
            'tenant_id' => $company, 'company_name' => 'Sharma Transport Pvt Ltd',
            // vendor_code is NOT NULL on the real table — this fixture builds a
            // row the CRM would actually accept, not a convenient stub.
            'vendor_code' => 'V-'.$company.'-'.Str::random(5),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        for ($i = 1; $i <= $count; $i++) {
            DB::table('tpv_workers')->insert([
                'tenant_id' => $company, 'vendor_id' => $vendorId,
                'worker_code' => 'W'.$company.'-'.Str::random(6), 'name' => 'Driver '.str_pad($i, 2, '0', STR_PAD_LEFT),
                'designation' => $designation, 'mobile' => '98765'.str_pad($i, 5, '0', STR_PAD_LEFT),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $vendorId;
    }

    /* ── Integrated: the whole point of the requirement ─────────── */

    public function test_forty_workers_added_under_a_vendor_appear_in_transport_untouched(): void
    {
        $this->vendorWithWorkers(40);

        $data = $this->actingAs($this->user())->getJson('/api/v1/fleet/drivers')
            ->assertOk()->json('data');

        // Nobody imported them, nobody re-typed them.
        $this->assertSame(40, $data['counts']['total']);
        $this->assertSame('CrmDriverDirectory', $data['source']);

        $first = collect($data['drivers'])->firstWhere('name', 'Driver 01');
        $this->assertSame('Sharma Transport Pvt Ltd', $first['employer']);
        $this->assertSame('vendor', $first['employer_type']);
        $this->assertSame('98765'.str_pad(1, 5, '0', STR_PAD_LEFT), $first['phone']);
        $this->assertSame('crm_tpv_worker', $first['source']);
    }

    public function test_a_correction_in_the_crm_directory_shows_immediately(): void
    {
        $this->vendorWithWorkers(1);

        // Somebody fixes the phone number in the customer directory.
        DB::table('tpv_workers')->where('name', 'Driver 01')->update(['mobile' => '9000000000']);

        $drivers = $this->actingAs($this->user())->getJson('/api/v1/fleet/drivers')->json('data.drivers');

        // No sync job, no stale copy — because there is no copy at all.
        $this->assertSame('9000000000', $drivers[0]['phone']);
    }

    public function test_transport_stores_no_copy_of_a_persons_identity(): void
    {
        $this->vendorWithWorkers(1);

        $this->actingAs($this->user())->putJson('/api/v1/fleet/drivers/crm_tpv_worker/1', [
            'licence_number' => 'MH0120110012345', 'licence_class' => 'HMV',
            'licence_expiry' => now()->addYear()->toDateString(),
        ])->assertOk();

        $profile = DriverProfile::first();

        // The overlay holds a REFERENCE and licence facts. No name, no phone —
        // those are the directory's, and a copy is what goes stale.
        $this->assertSame('crm_tpv_worker:1', $profile->ref);
        $this->assertSame('MH0120110012345', $profile->licence_number);
        $this->assertNotContains('name', array_keys($profile->getAttributes()));
        $this->assertNotContains('phone', array_keys($profile->getAttributes()));
    }

    public function test_people_are_pulled_from_every_directory_the_crm_has(): void
    {
        $vendorId = $this->vendorWithWorkers(1);

        DB::table('vendor_contacts')->insert([
            'tenant_id' => self::COMPANY, 'vendor_id' => $vendorId, 'name' => 'Vendor Contact',
            'designation' => 'Fleet manager', 'phone' => '9111111111',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $clientId = DB::table('clients')->insertGetId([
            'tenant_id' => self::COMPANY, 'company' => 'ABC Pharma', 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('client_contacts')->insert([
            'tenant_id' => self::COMPANY, 'client_id' => $clientId,
            'first_name' => 'Customer', 'last_name' => 'Person', 'phone' => '9222222222',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $drivers = $this->actingAs($this->user())->getJson('/api/v1/fleet/drivers')->json('data.drivers');
        $names = collect($drivers)->pluck('name');

        $this->assertTrue($names->contains('Driver 01'));
        $this->assertTrue($names->contains('Vendor Contact'));
        // first_name + last_name are joined into one name by the adapter.
        $this->assertTrue($names->contains('Customer Person'));

        $customer = collect($drivers)->firstWhere('name', 'Customer Person');
        $this->assertSame('ABC Pharma', $customer['employer']);
        $this->assertSame('customer', $customer['employer_type']);
    }

    public function test_one_company_never_sees_another_companys_people(): void
    {
        $this->vendorWithWorkers(3, self::COMPANY);
        $this->vendorWithWorkers(5, self::OTHER);

        $data = $this->actingAs($this->user())->getJson('/api/v1/fleet/drivers')->json('data');

        $this->assertSame(3, $data['counts']['total'], 'Another company\'s workforce leaked into this directory');
    }

    public function test_the_drivers_only_filter_matches_on_designation_keywords(): void
    {
        $this->vendorWithWorkers(2, self::COMPANY, 'Driver');
        $this->vendorWithWorkers(3, self::COMPANY, 'Welder');

        $all = $this->actingAs($this->user())->getJson('/api/v1/fleet/drivers')->json('data.counts.total');
        $onlyDrivers = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/drivers?drivers_only=1')->json('data.counts.total');

        $this->assertSame(5, $all);
        $this->assertSame(2, $onlyDrivers);
    }

    /* ── The licence gate ───────────────────────────────────────── */

    public function test_a_licence_is_judged_the_same_way_a_vehicle_document_is(): void
    {
        $this->vendorWithWorkers(1);
        $url = '/api/v1/fleet/drivers/crm_tpv_worker/1';

        // Nothing recorded: not a pass and not a block.
        $drivers = $this->actingAs($this->user())->getJson('/api/v1/fleet/drivers')->json('data.drivers');
        $this->assertSame('unknown', $drivers[0]['licence']['state']);

        $this->actingAs($this->user())->putJson($url, ['licence_expiry' => now()->subDay()->toDateString()])
            ->assertOk()->assertJsonPath('data.licence.state', 'expired');

        $this->actingAs($this->user())->putJson($url, ['licence_expiry' => now()->addDays(10)->toDateString()])
            ->assertOk()->assertJsonPath('data.licence.state', 'expiring');

        // Valid THROUGH the expiry date, exactly like a vehicle's papers.
        $this->actingAs($this->user())->putJson($url, ['licence_expiry' => now()->toDateString()])
            ->assertOk()->assertJsonPath('data.licence.state', 'expiring');
    }

    public function test_an_overlay_cannot_be_written_for_somebody_who_does_not_exist(): void
    {
        $this->actingAs($this->user())
            ->putJson('/api/v1/fleet/drivers/crm_tpv_worker/9999', ['licence_number' => 'X'])
            ->assertNotFound();

        $this->assertSame(0, DriverProfile::count());
    }

    public function test_an_overlay_cannot_be_written_against_another_companys_person(): void
    {
        $this->vendorWithWorkers(1, self::OTHER);

        $this->actingAs($this->user())
            ->putJson('/api/v1/fleet/drivers/crm_tpv_worker/1', ['licence_number' => 'X'])
            ->assertNotFound();
    }

    /* ── Standalone: the same screens, no CRM ───────────────────── */

    public function test_the_same_service_runs_against_the_standalone_register(): void
    {
        // Force standalone, as a deployment with no CRM around it would.
        $this->app->bind(DriverDirectory::class, fn () => new StandaloneDriverDirectory());

        DB::table('stos_drivers')->insert([
            'company_id' => self::COMPANY, 'name' => 'Standalone Rajesh',
            'phone' => '9333333333', 'employer_name' => 'Own fleet', 'designation' => 'Driver',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(DriverService::class)->list(self::COMPANY);

        // Identical shape — no screen or service can tell which source it is on.
        $this->assertSame(1, $result['counts']['total']);
        $this->assertSame('Standalone Rajesh', $result['drivers'][0]['name']);
        $this->assertSame('stos:1', $result['drivers'][0]['ref']);
        $this->assertStringContainsString('standalone', strtolower($result['directory']));
    }

    public function test_the_overlay_works_identically_in_standalone_mode(): void
    {
        $this->app->bind(DriverDirectory::class, fn () => new StandaloneDriverDirectory());

        DB::table('stos_drivers')->insert([
            'company_id' => self::COMPANY, 'name' => 'Standalone Rajesh',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $saved = app(DriverService::class)->saveProfile(
            self::COMPANY, 'stos', 1,
            ['licence_number' => 'DL-1', 'licence_expiry' => now()->addYear()->toDateString()],
            1
        );

        $this->assertSame('DL-1', $saved['profile']['licence_number']);
        $this->assertSame('valid', $saved['licence']['state']);
    }

    public function test_the_crm_adapter_degrades_rather_than_crashing_on_a_missing_module(): void
    {
        // A deployment without the TPV module installed.
        Schema::dropIfExists('tpv_workers');

        $directory = new CrmDriverDirectory();

        // No exception, and the description tells the truth about what is left.
        $this->assertIsArray($directory->people(self::COMPANY));
        $this->assertStringNotContainsString('TPV workforce', $directory->describe());
    }

    public function test_a_portal_login_cannot_read_the_driver_directory(): void
    {
        $this->vendorWithWorkers(2);

        foreach (['client', 'vendor', 'third_party_vendor'] as $role) {
            $this->actingAs($this->user($role))->getJson('/api/v1/fleet/drivers')->assertForbidden();
        }
    }
}
