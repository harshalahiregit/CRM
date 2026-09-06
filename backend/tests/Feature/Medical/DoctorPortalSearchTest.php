<?php

namespace Tests\Feature\Medical;

use App\Models\Medical\MedicalDoctorProfile;
use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Finding a person, when there are thousands of them.
 *
 * Two faults this pins.
 *
 * The vendor list was a plain dropdown fed by a query capped at 500 rows. With a
 * thousand vendors that is a menu which silently stops halfway — the five
 * hundred and first vendor does not exist as far as the doctor can tell, and
 * nothing on screen admits it. Every list now reports how many actually matched.
 *
 * And picking a vendor was REQUIRED before a worker could be looked for at all.
 * That is backwards: a doctor is handed a name or a worker code, not a company.
 * Being made to remember which of a thousand vendors employs Ramesh, before
 * being allowed to search for Ramesh, was the software's problem to solve.
 */
class DoctorPortalSearchTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant)->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function doctor(): User
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Dr Rao', 'role' => 'doctor',
            'email' => 'doc-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        MedicalDoctorProfile::create([
            'tenant_id' => self::TENANT, 'user_id' => $user->id, 'license_no' => 'MH-1', 'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function vendor(string $name): Vendor
    {
        return Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'vendor_code' => 'V-'.Str::random(6), 'status' => 'active',
        ]);
    }

    private function worker(Vendor $v, string $name): TpvWorker
    {
        return TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id,
            'name' => $name, 'worker_code' => 'W-'.Str::random(6),
        ]);
    }

    /* ── A worker can be found without knowing their vendor ─────────────── */

    public function test_workers_can_be_searched_without_choosing_a_vendor_first(): void
    {
        // This used to be a 422: "Select a vendor first."
        $a = $this->vendor('Alpha Contractors');
        $b = $this->vendor('Bravo Services');
        $this->worker($a, 'Ramesh Patil');
        $this->worker($b, 'Suresh Kumar');
        $this->doctor();

        $rows = $this->getJson('/api/doctor/tpv/workers?q=Ramesh')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Ramesh Patil', $rows[0]['name']);
    }

    public function test_a_worker_row_names_its_vendor(): void
    {
        // Searching across vendors is only useful if the answer says which
        // vendor the person belongs to.
        $this->worker($this->vendor('Alpha Contractors'), 'Ramesh Patil');
        $this->doctor();

        $rows = $this->getJson('/api/doctor/tpv/workers?q=Ramesh')->assertOk()->json('data');

        $this->assertSame('Alpha Contractors', $rows[0]['context']);
    }

    public function test_a_worker_can_be_found_by_code(): void
    {
        $worker = $this->worker($this->vendor('Alpha'), 'Ramesh Patil');
        $this->doctor();

        $rows = $this->getJson('/api/doctor/tpv/workers?q='.$worker->worker_code)->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($worker->id, $rows[0]['id']);
    }

    public function test_the_vendor_still_works_as_a_filter(): void
    {
        $a = $this->vendor('Alpha');
        $b = $this->vendor('Bravo');
        $this->worker($a, 'One');
        $this->worker($a, 'Two');
        $this->worker($b, 'Three');
        $this->doctor();

        $rows = $this->getJson("/api/doctor/tpv/workers?vendor_id={$a->id}")->assertOk()->json('data');
        $this->assertCount(2, $rows);
    }

    /* ── Nothing silently stops ─────────────────────────────────────────── */

    public function test_a_long_vendor_list_reports_what_it_is_not_showing(): void
    {
        // The old dropdown returned 500 rows and said nothing about the rest.
        foreach (range(1, 60) as $i) {
            $this->vendor('Vendor '.str_pad($i, 3, '0', STR_PAD_LEFT));
        }
        $this->doctor();

        $body = $this->getJson('/api/doctor/tpv/vendors')->assertOk()->json();

        $this->assertSame(60, $body['meta']['total']);
        $this->assertSame(50, $body['meta']['showing'], 'a page, not the lot');
        $this->assertTrue($body['meta']['truncated'], 'the screen is told there is more');
    }

    public function test_searching_vendors_narrows_and_the_total_follows(): void
    {
        foreach (range(1, 60) as $i) {
            $this->vendor('Vendor '.str_pad($i, 3, '0', STR_PAD_LEFT));
        }
        $this->vendor('Distinctive Engineering');
        $this->doctor();

        $body = $this->getJson('/api/doctor/tpv/vendors?q=Distinctive')->assertOk()->json();

        $this->assertSame(1, $body['meta']['total']);
        $this->assertFalse($body['meta']['truncated']);
        $this->assertSame('Distinctive Engineering', $body['data'][0]['name']);
    }

    public function test_a_vendor_can_be_found_by_its_code(): void
    {
        $v = $this->vendor('Alpha Contractors');
        $this->doctor();

        $rows = $this->getJson('/api/doctor/tpv/vendors?q='.$v->vendor_code)->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($v->id, $rows[0]['id']);
    }

    public function test_a_long_worker_list_reports_its_total_too(): void
    {
        $v = $this->vendor('Alpha');
        foreach (range(1, 60) as $i) {
            $this->worker($v, 'Worker '.str_pad($i, 3, '0', STR_PAD_LEFT));
        }
        $this->doctor();

        $body = $this->getJson('/api/doctor/tpv/workers')->assertOk()->json();

        $this->assertSame(60, $body['meta']['total']);
        $this->assertSame(50, $body['meta']['showing']);
        $this->assertTrue($body['meta']['truncated']);
    }

    /* ── The general audiences behave the same way ──────────────────────── */

    public function test_internal_people_are_searchable_and_counted(): void
    {
        foreach (range(1, 60) as $i) {
            User::create([
                'tenant_id' => self::TENANT, 'name' => 'Staff '.str_pad($i, 3, '0', STR_PAD_LEFT),
                'role' => 'staff', 'email' => "s{$i}@t.local", 'password' => bcrypt('x'), 'status' => 'active',
            ]);
        }
        User::create([
            'tenant_id' => self::TENANT, 'name' => 'Distinctive Person', 'role' => 'staff',
            'email' => 'dp@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $this->doctor();

        $all = $this->getJson('/api/doctor/internal/people')->assertOk()->json();
        $this->assertTrue($all['meta']['truncated']);
        $this->assertSame(50, $all['meta']['showing']);

        $found = $this->getJson('/api/doctor/internal/people?q=Distinctive')->assertOk()->json();
        $this->assertSame(1, $found['meta']['total']);
        $this->assertSame('Distinctive Person', $found['data'][0]['name']);
    }

    /* ── Scoping survives the loosened rules ────────────────────────────── */

    public function test_searching_never_reaches_another_workspace(): void
    {
        // Dropping the mandatory vendor widened what a search can see, so this
        // is exactly where a tenant leak would appear.
        (new Tenant)->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $theirVendor = Vendor::create([
            'tenant_id' => 2, 'company_name' => 'Theirs Ltd', 'vendor_code' => 'V-THEIR', 'status' => 'active',
        ]);
        TpvWorker::create([
            'tenant_id' => 2, 'vendor_id' => $theirVendor->id, 'name' => 'Ramesh Patil', 'worker_code' => 'W-THEIR',
        ]);
        $this->worker($this->vendor('Mine'), 'Ramesh Patil');
        $this->doctor();

        $rows = $this->getJson('/api/doctor/tpv/workers?q=Ramesh')->assertOk()->json('data');
        $this->assertCount(1, $rows, 'only this workspace');

        $vendors = $this->getJson('/api/doctor/tpv/vendors?q=Theirs')->assertOk()->json('data');
        $this->assertCount(0, $vendors);
    }
}
