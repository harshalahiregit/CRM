<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Directory\CompositeDriverDirectory;
use App\Domains\Fleet\Directory\CrmDriverDirectory;
use App\Domains\Fleet\Directory\StandaloneDriverDirectory;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D-134 — both registers, and the refusal that keeps a handle a handle.
 *
 * After the D-62 move a CRM installation holds drivers in the CRM directories
 * AND in `stos_drivers`, because the move put the drivers it found into the
 * local register — correctly, there being no CRM person to point at. `auto`
 * picked one, so the migrated drivers vanished from allocation entirely.
 */
class CompositeDriverDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::COMPANY, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function composite(): CompositeDriverDirectory
    {
        return new CompositeDriverDirectory(new CrmDriverDirectory(), new StandaloneDriverDirectory());
    }

    /** A customer contact whose title reads as a driver, which is what the CRM register offers. */
    private function crmDriver(string $first, string $last): int
    {
        $clientId = DB::table('clients')->insertGetId([
            'tenant_id' => self::COMPANY, 'company' => 'Sharma Transport',
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('client_contacts')->insertGetId([
            'tenant_id' => self::COMPANY, 'client_id' => $clientId,
            'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first).'@sharma.test', 'phone' => '9820000001',
            'title' => 'Driver', 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function localDriver(string $name): int
    {
        return DB::table('stos_drivers')->insertGetId([
            'company_id' => self::COMPANY, 'name' => $name,
            'phone' => '98200000'.random_int(10, 99), 'designation' => 'Driver',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_locally_registered_driver_is_offered_even_inside_a_crm_install(): void
    {
        $id = $this->localDriver('Suresh Patil');

        $refs = array_column($this->composite()->people(self::COMPANY), 'ref');

        $this->assertContains('stos:'.$id, $refs,
            'A driver in the STOS register is invisible to allocation. This is D-134: the move '
            .'migration put migrated drivers here deliberately, and a directory that reads only the '
            .'CRM cannot see them.');
    }

    public function test_the_crm_register_is_not_lost_to_make_room_for_the_local_one(): void
    {
        $this->localDriver('Suresh Patil');
        $this->crmDriver('Rajesh', 'Kumar');

        $crmOnly = array_column((new CrmDriverDirectory())->people(self::COMPANY), 'ref');
        $both = array_column($this->composite()->people(self::COMPANY), 'ref');

        // Without this the loop below has nothing to iterate and the test
        // passes by doing nothing — which is the failure mode it exists to
        // catch, wearing a tick.
        $this->assertNotEmpty($crmOnly, 'no CRM person was seeded, so this proves nothing');

        foreach ($crmOnly as $ref) {
            $this->assertContains($ref, $both,
                "Ref {$ref} was offered by the CRM directory and is missing from the composite. "
                .'Forcing `standalone` would have done this to every CRM driver — inverting D-134 '
                .'rather than fixing it.');
        }
    }

    /**
     * The property the namespaced refs buy, and the reason find() dispatches
     * rather than trying both.
     *
     * Each directory must keep REFUSING a ref it does not own. If a refusal
     * ever became a fallback — "try the other one" — two registers could answer
     * for one handle, and the handle would stop identifying a person.
     */
    public function test_each_register_still_refuses_the_other_register_handles(): void
    {
        $id = $this->localDriver('Suresh Patil');

        $this->assertNull((new CrmDriverDirectory())->find(self::COMPANY, 'stos', $id),
            'The CRM directory answered for a `stos:` handle. Refusal is the property the '
            .'namespacing buys; if it becomes a fallback, a ref no longer names one person.');

        $this->assertNull((new StandaloneDriverDirectory())->find(self::COMPANY, 'crm_client_contact', 1),
            'The standalone register answered for a `crm_client_contact:` handle.');
    }

    public function test_the_composite_routes_each_handle_to_the_register_that_owns_it(): void
    {
        $id = $this->localDriver('Suresh Patil');

        $found = $this->composite()->find(self::COMPANY, 'stos', $id);

        $this->assertNotNull($found, 'The composite could not resolve a handle it had just offered.');
        $this->assertSame('Suresh Patil', $found['name']);

        $this->assertNull($this->composite()->find(self::COMPANY, 'stos', $id + 9999),
            'The composite invented a person for an id that does not exist.');
    }

    /** A UI that says where its people come from must not name only half. */
    public function test_it_says_it_is_reading_both(): void
    {
        $said = strtolower($this->composite()->describe());

        $this->assertStringContainsString('stos driver register', $said);
        $this->assertStringContainsString('workforce', $said);
    }
}
