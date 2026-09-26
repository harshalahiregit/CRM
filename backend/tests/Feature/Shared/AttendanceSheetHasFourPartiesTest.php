<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The attendance sheet is four columns of REAL people.
 *
 * The old participant picker listed a vendor as a single row carrying the
 * company: name "Acme Fabrication", designation empty. A meeting was therefore
 * minuted as attended by a company — which cannot be marked present, cannot be
 * assigned an action, and tells a reader nothing about who was in the room.
 * Everyone else was typed by hand into a seven-field card, so the same site
 * engineer arrived with a different spelling at every meeting.
 *
 * These assertions cover the part that is easy to get quietly wrong: that each
 * of the four columns resolves to people from the right table, that a person
 * comes back with the designation from their own record, and that both engines
 * answer identically — Purchase's copy of this UI has drifted from the shared
 * one every previous time a picker was added to only one of them.
 */
class AttendanceSheetHasFourPartiesTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Priya Nair', 'email' => 'priya@t1.test',
            'password' => bcrypt('secret-secret'), 'role' => 'admin', 'status' => 'active',
            'designation' => 'Procurement Head',
        ]);
    }

    /** Both engines expose the same two endpoints, under their own prefixes. */
    public static function engines(): array
    {
        return [
            'shared'   => ['/api/kickoff/parties', '/api/kickoff/party-people'],
            'purchase' => ['/api/purchase/kickoff/parties', '/api/purchase/kickoff/party-people'],
        ];
    }

    /**
     * @dataProvider engines
     */
    public function test_the_sheet_offers_exactly_the_four_parties(string $parties): void
    {
        Sanctum::actingAs($this->admin);

        $keys = collect($this->getJson($parties)->assertOk()->json('parties'))->pluck('key')->all();

        $this->assertSame(['organiser', 'client', 'vendor', 'tpv'], $keys,
            'the attendance sheet is Organiser / Client / Vendor / Third-Party Vendor, in that order — '
            .'the grid renders one column per entry and the order is the reading order of the sheet');
    }

    /**
     * The organiser column needs no company step, so its people must arrive
     * with the columns themselves — otherwise the first column of the grid is
     * empty until somebody picks a company that does not exist for it.
     */
    public function test_the_organiser_column_arrives_with_its_people(): void
    {
        Sanctum::actingAs($this->admin);

        $organiser = collect($this->getJson('/api/kickoff/parties')->assertOk()->json('parties'))
            ->firstWhere('key', 'organiser');

        $this->assertFalse($organiser['picks_entity']);
        $this->assertNotEmpty($organiser['people']);

        $me = collect($organiser['people'])->firstWhere('user_id', $this->admin->id);
        $this->assertNotNull($me, 'an active internal user is missing from the organiser column');
        $this->assertSame('Procurement Head', $me['designation']);
        $this->assertSame('user:'.$this->admin->id, $me['ref']);
    }

    /**
     * A third-party vendor's column lists that vendor's OWN workforce, with the
     * designation held against each worker — not the company as one row.
     */
    public function test_a_third_party_vendor_column_lists_that_vendors_workforce(): void
    {
        Sanctum::actingAs($this->admin);

        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme Fabrication',
            'vendor_code' => 'TPV-001', 'status' => 'Active', 'email' => 'ops@acme.test',
        ]);

        $workerId = DB::table('tpv_workers')->insertGetId([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'worker_code' => 'W-1', 'name' => 'Ravi Shankar', 'designation' => 'Site Engineer',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $people = $this->getJson('/api/kickoff/party-people?party=tpv&entity_id='.$vendor->id)
            ->assertOk()->json('people');

        $ravi = collect($people)->firstWhere('ref', 'tpv_worker:'.$workerId);

        $this->assertNotNull($ravi, "the vendor's own worker is not offered in its column");
        $this->assertSame('Ravi Shankar', $ravi['name']);
        $this->assertSame('Site Engineer', $ravi['designation'],
            'the designation must come from the worker record — typing it again is what the grid removes');
        $this->assertSame('Acme Fabrication', $ravi['organisation']);

        // And the company itself is an ENTITY to choose, never a person to invite.
        $tpv = collect($this->getJson('/api/kickoff/parties')->assertOk()->json('parties'))
            ->firstWhere('key', 'tpv');
        $this->assertContains('Acme Fabrication', collect($tpv['entities'])->pluck('name')->all());
        $this->assertEmpty($tpv['people'],
            'companies belong in `entities`; a company in `people` is the old bug where a meeting '
            .'recorded a firm as having attended it');
    }

    /**
     * A third-party vendor's column also lists its CONTACTS — the people its
     * Contacts tab holds, not only badged workers.
     *
     * This column read the legacy `vendor_contacts` table, which no TPV screen
     * writes: the Contacts tab saves to `tpv_contacts`. So a vendor with staff
     * but no workers offered nobody at all — "No people are registered against
     * <vendor> yet" — while the same picker worked on the Purchase side, which
     * had never drifted from its own table. The suite missed it because every
     * case here inserted workers and none inserted a contact.
     */
    public function test_a_third_party_vendors_contacts_are_offered_too(): void
    {
        Sanctum::actingAs($this->admin);

        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Sangoe Fabrication',
            'vendor_code' => 'TPV-002', 'status' => 'Active', 'email' => 'ops@sangoe.test',
        ]);

        $contactId = DB::table('tpv_contacts')->insertGetId([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'first_name' => 'Heera', 'last_name' => 'Lal', 'designation' => 'Manager',
            'email' => 'heera@sangoe.test', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Deliberately NO workers on this vendor: contacts alone must fill the column.
        $people = $this->getJson('/api/kickoff/party-people?party=tpv&entity_id='.$vendor->id)
            ->assertOk()->json('people');

        $this->assertNotEmpty($people,
            'a vendor with contacts and no workers offered nobody — the column read the wrong table');

        $heera = collect($people)->firstWhere('ref', 'tpv_contact:'.$contactId);

        $this->assertNotNull($heera, "the vendor's own contact is not offered in its column");
        $this->assertSame('Heera Lal', $heera['name'], 'first and last name are joined for display');
        $this->assertSame('Manager', $heera['designation']);
        $this->assertSame('heera@sangoe.test', $heera['email'],
            'the e-mail must come across, or the invitation cannot reach them');
        $this->assertSame('Sangoe Fabrication', $heera['organisation']);
    }

    /** A deleted contact is not offered. */
    public function test_a_removed_contact_is_not_offered(): void
    {
        Sanctum::actingAs($this->admin);

        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Gone Traders',
            'vendor_code' => 'TPV-003', 'status' => 'Active', 'email' => 'ops@gone.test',
        ]);

        DB::table('tpv_contacts')->insert([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'first_name' => 'Removed', 'last_name' => 'Person', 'status' => 'Active',
            'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $people = $this->getJson('/api/kickoff/party-people?party=tpv&entity_id='.$vendor->id)
            ->assertOk()->json('people');

        $this->assertEmpty($people, 'a deleted contact must not be invitable');
    }

    /** The Vendor column is Purchase's vendors, which are a separate master. */
    public function test_the_vendor_column_is_the_purchase_vendor_master(): void
    {
        Sanctum::actingAs($this->admin);

        $pv = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-001', 'status' => 'Active', 'email' => 'sales@bolt.test',
        ]);

        $workerId = DB::table('purchase_workers')->insertGetId([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $pv->id,
            'worker_code' => 'PW-1', 'full_name' => 'Meera Das', 'designation' => 'Storekeeper',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $people = $this->getJson('/api/kickoff/party-people?party=vendor&entity_id='.$pv->id)
            ->assertOk()->json('people');

        $meera = collect($people)->firstWhere('ref', 'purchase_worker:'.$workerId);
        $this->assertNotNull($meera);
        $this->assertSame('Storekeeper', $meera['designation']);
    }

    /** An unknown party is refused rather than quietly answering an empty list. */
    public function test_an_unknown_party_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/kickoff/party-people?party=regulator&entity_id=1')
            ->assertStatus(422);
    }

    /**
     * And the column a person was filed under survives a save.
     *
     * Without this the grid rebuilds itself on every edit by guessing, and a
     * meeting reopened after saving shows everybody in "not yet placed".
     */
    public function test_the_party_is_stored_on_the_participant_row(): void
    {
        Sanctum::actingAs($this->admin);

        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme Fabrication',
            'vendor_code' => 'TPV-002', 'status' => 'Active', 'email' => 'ops@acme2.test',
        ]);

        $res = $this->postJson('/api/kickoff/meetings', [
            'title' => 'Site kickoff',
            'subject_type' => 'vendor',
            'subject_id' => $vendor->id,
            // The engine takes real timestamps, not a date and a time.
            'scheduled_at' => now()->addDays(3)->setTime(10, 0)->toDateTimeString(),
            'end_at' => now()->addDays(3)->setTime(11, 0)->toDateTimeString(),
            'mode' => 'onsite',
            'location' => 'Plant 2',
            'attendees' => [[
                'name' => 'Ravi Shankar',
                'designation' => 'Site Engineer',
                'side' => 'external',
                'party' => 'tpv',
                'party_ref' => 'tpv_worker:99',
                'email' => 'ravi@acme.test',
            ]],
        ]);

        $res->assertSuccessful();

        $row = DB::table('kickoff_attendees')->where('name', 'Ravi Shankar')->first();

        $this->assertNotNull($row);
        $this->assertSame('tpv', $row->party);
        $this->assertSame('tpv_worker:99', $row->party_ref);
    }
}
