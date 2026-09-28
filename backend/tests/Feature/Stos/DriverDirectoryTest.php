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

        // D-144(i) — this asserted `CrmDriverDirectory`, which is the binding
        // Person 1's D-134 fix deliberately replaced: after the D-62 move both
        // the CRM directory and the STOS register hold real people, and
        // choosing one hid the other. He left the test red rather than edit my
        // file, which is the right way round. The claim the test actually
        // makes — forty people entered in the CRM appear here with nobody
        // re-typing them — is unchanged, and is asserted below.
        $this->assertSame('CompositeDriverDirectory', $data['source']);

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

    public function test_a_licence_that_has_not_started_yet_is_not_valid(): void
    {
        // D-151(i) — licence_valid_from was ignored, so a licence dated to begin
        // next week read as valid on an unexpired expiry, and the driver was
        // dispatchable on a licence that had not taken effect. The old Transport
        // check refused it.
        $this->vendorWithWorkers(1);
        $url = '/api/v1/fleet/drivers/crm_tpv_worker/1';

        // A real, unexpired licence: on expiry alone this is 'valid'.
        $this->actingAs($this->user())->putJson($url, ['licence_expiry' => now()->addYear()->toDateString()])
            ->assertOk()->assertJsonPath('data.licence.state', 'valid');

        // But it does not take effect until next week. No Fleet write path sets
        // this column yet (it came across with the move), so set it directly.
        DriverProfile::query()->update(['licence_valid_from' => now()->addWeek()->toDateString()]);

        $drivers = $this->actingAs($this->user())->getJson('/api/v1/fleet/drivers')->json('data.drivers');
        $this->assertSame('not_yet_valid', $drivers[0]['licence']['state']);

        // And it blocks: the driver is excluded from eligibility, naming why.
        $eligibility = $this->actingAs($this->user())->getJson('/api/v1/fleet/drivers/eligible')->json('data');
        $this->assertEmpty($eligibility['eligible']);
        $codes = collect($eligibility['excluded'][0]['blockers'])->pluck('code');
        $this->assertContains('driver_license_not_yet_valid', $codes);
    }

    public function test_a_licence_already_in_effect_is_judged_on_its_expiry(): void
    {
        // The other side: a start date in the PAST must not change anything —
        // the verdict falls through to the ordinary expiry arithmetic.
        $this->vendorWithWorkers(1);
        $url = '/api/v1/fleet/drivers/crm_tpv_worker/1';

        $this->actingAs($this->user())->putJson($url, ['licence_expiry' => now()->addYear()->toDateString()])
            ->assertOk()->assertJsonPath('data.licence.state', 'valid');

        DriverProfile::query()->update(['licence_valid_from' => now()->subYear()->toDateString()]);

        $drivers = $this->actingAs($this->user())->getJson('/api/v1/fleet/drivers')->json('data.drivers');
        $this->assertSame('valid', $drivers[0]['licence']['state']);
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

    /* ── D-145: one licence, one driver ─────────────────────────── */

    public function test_a_second_driver_cannot_be_given_a_licence_another_driver_holds(): void
    {
        // Two real people in the directory, as a vendor would supply them.
        $this->vendorWithWorkers(2);

        $this->actingAs($this->user())
            ->putJson('/api/v1/fleet/drivers/crm_tpv_worker/1', ['licence_number' => 'RJ14 20110012345'])
            ->assertOk();

        // The same licence written differently is the SAME licence — this is
        // why the check normalises rather than comparing the typed strings.
        $refusal = $this->actingAs($this->user())
            ->putJson('/api/v1/fleet/drivers/crm_tpv_worker/2', ['licence_number' => 'rj-14-2011-0012345'])
            ->assertStatus(422);

        // The refusal names the person who holds it, not a column.
        $this->assertStringContainsString('Driver 01', $refusal->json('message'));

        $this->assertSame(1, DriverProfile::whereNotNull('licence_number')->count());
    }

    public function test_a_driver_may_have_their_own_licence_corrected(): void
    {
        // The guard must not fire on the holder themselves, or nobody could
        // ever fix a typo in a licence number they already saved.
        $this->vendorWithWorkers(1);

        $this->actingAs($this->user())
            ->putJson('/api/v1/fleet/drivers/crm_tpv_worker/1', ['licence_number' => 'RJ1420110012345'])
            ->assertOk();

        $this->actingAs($this->user())
            ->putJson('/api/v1/fleet/drivers/crm_tpv_worker/1', [
                'licence_number' => 'RJ1420110012345', 'licence_class' => 'HMV',
            ])->assertOk();

        $this->assertSame('HMV', DriverProfile::first()->licence_class);
    }

    public function test_many_drivers_may_have_no_licence_recorded_yet(): void
    {
        // "Not recorded yet" is a normal state — a profile exists to hold a
        // medical date or a vehicle assignment before anybody types a licence.
        // If blanks collided under the unique index, the second save would
        // fail, and the register would be unusable for exactly the drivers
        // whose paperwork is still being chased.
        $this->vendorWithWorkers(3);

        foreach ([1, 2, 3] as $person) {
            $this->actingAs($this->user())
                ->putJson('/api/v1/fleet/drivers/crm_tpv_worker/'.$person, ['status' => DriverProfile::AVAILABLE])
                ->assertOk();
        }

        $this->assertSame(3, DriverProfile::count());
        $this->assertSame(3, DriverProfile::whereNull('licence_normalized')->count());
    }

    public function test_the_database_refuses_a_duplicate_licence_even_without_the_service(): void
    {
        // The service gives the readable sentence; the index is what actually
        // holds the rule. A seeder, a repair script or a tinker session does
        // not go through the service, and D-145 happened because the only
        // enforcement lived on a table that became read-only.
        $this->vendorWithWorkers(2);

        DriverProfile::create([
            'company_id' => self::COMPANY, 'source' => 'crm_tpv_worker', 'source_id' => 1,
            'licence_number' => 'RJ1420110012345', 'status' => DriverProfile::AVAILABLE,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DriverProfile::create([
            'company_id' => self::COMPANY, 'source' => 'crm_tpv_worker', 'source_id' => 2,
            'licence_number' => 'rj-14-2011-0012345', 'status' => DriverProfile::AVAILABLE,
        ]);
    }

    public function test_two_workspaces_may_each_record_the_same_person(): void
    {
        // The index is scoped to the workspace, matching every other rule in
        // this module: two companies on one installation may legitimately both
        // employ the same driver, and neither may see the other's register.
        $this->vendorWithWorkers(1, self::COMPANY);
        $this->vendorWithWorkers(1, self::OTHER);

        foreach ([self::COMPANY, self::OTHER] as $company) {
            $this->actingAs($this->user('staff', $company))
                ->putJson('/api/v1/fleet/drivers/crm_tpv_worker/'.($company === self::COMPANY ? 1 : 2), [
                    'licence_number' => 'RJ1420110012345',
                ])->assertOk();
        }

        $this->assertSame(2, DriverProfile::withoutGlobalScopes()->whereNotNull('licence_number')->count());
    }

    /* ── Registering a driver STOS owns itself ───────────────────── */

    public function test_a_locally_registered_driver_appears_on_the_board(): void
    {
        // A haulier's own driver — not a customer or vendor contact.
        $person = $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/drivers', ['name' => 'Ramesh Kumar', 'phone' => '9876543210'])
            ->assertCreated()->json('data');

        $this->assertSame('stos', $person['source']);
        $this->assertSame('Ramesh Kumar', $person['name']);

        // Shows up in the directory list, alongside anyone from the CRM.
        $list = $this->actingAs($this->user())->getJson('/api/v1/fleet/drivers')->json('data');
        $this->assertContains('Ramesh Kumar', collect($list['drivers'])->pluck('name'));

        // And takes a licence overlay by the handle it was given.
        $this->actingAs($this->user())
            ->putJson('/api/v1/fleet/drivers/stos/'.$person['source_id'], ['licence_number' => 'MH1220110099887'])
            ->assertOk()->assertJsonPath('data.profile.licence_number', 'MH1220110099887');
    }

    public function test_a_registered_driver_and_a_crm_person_share_one_board(): void
    {
        // Part B — the register does not replace the directory; both are read.
        $this->vendorWithWorkers(2);
        $this->actingAs($this->user())->postJson('/api/v1/fleet/drivers', ['name' => 'Own Driver'])->assertCreated();

        $list = $this->actingAs($this->user())->getJson('/api/v1/fleet/drivers')->json('data');
        $names = collect($list['drivers'])->pluck('name');

        $this->assertContains('Own Driver', $names);          // from the STOS register
        $this->assertContains('Driver 01', $names);            // from the CRM vendor
        // The banner names both sources, so nobody wonders where a name came from.
        $directory = strtolower((string) $list['directory']);
        $this->assertStringContainsString('stos driver register', $directory);
        $this->assertStringContainsString('contacts', $directory);
    }

    public function test_registering_the_identical_person_twice_is_refused(): void
    {
        $body = ['name' => 'Ramesh Kumar', 'phone' => '9998887777'];
        $this->actingAs($this->user())->postJson('/api/v1/fleet/drivers', $body)->assertCreated();
        $this->actingAs($this->user())->postJson('/api/v1/fleet/drivers', $body)->assertStatus(422);
    }

    public function test_a_driver_needs_a_name_to_be_registered(): void
    {
        $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/drivers', ['phone' => '9'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_a_portal_login_cannot_register_a_driver(): void
    {
        foreach (['client', 'vendor', 'third_party_vendor'] as $role) {
            $this->actingAs($this->user($role))
                ->postJson('/api/v1/fleet/drivers', ['name' => 'X'])
                ->assertForbidden();
        }
    }

    public function test_a_portal_login_cannot_read_the_driver_directory(): void
    {
        $this->vendorWithWorkers(2);

        foreach (['client', 'vendor', 'third_party_vendor'] as $role) {
            $this->actingAs($this->user($role))->getJson('/api/v1/fleet/drivers')->assertForbidden();
        }
    }
}
