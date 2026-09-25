<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The legacy masters refuse writes, and say where to go — D-143.
 *
 * Replaces eleven tests that asserted these endpoints WORK. They did, until the
 * owner ruled creation belongs to Fleet; asserting it forty times over was
 * noise, and noise is what this suite is being cleared of.
 *
 * ── WHY THE ROUTES ARE STILL REGISTERED ──────────────────────────────────
 * They were unrouted first, and that contradicted itself: an absent route
 * returns a bare 404 and names nothing. The screens still exist because they
 * still show history, so somebody will have a stale form open — a 404 tells
 * them the product is broken, a sentence tells them where to go. It also makes
 * the assertion below possible at all: "no role bypasses this" is vacuous
 * against a route that is not there.
 *
 * `POST /drivers` is deliberately NOT covered here. It is held under D-145
 * until Fleet enforces licence uniqueness — refusing it first would open a
 * window in which nothing in the system checks, and our own ruling would be
 * what opened it.
 */
class MasterWritesRefuseReadablyTest extends TestCase
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

    private function actAs(string $role): User
    {
        $user = User::create([
            'tenant_id' => self::COMPANY, 'name' => ucfirst($role), 'email' => $role.'@x.test',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'active',
        ]);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function legacyVehicleId(): int
    {
        return DB::table('transport_vehicles')->insertGetId([
            'tenant_id' => self::COMPANY, 'registration_number' => $plate = 'MH01OLD'.random_int(1000, 9999),
            'registration_normalized' => $plate,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function legacyDriverId(): int
    {
        return DB::table('transport_drivers')->insertGetId([
            'tenant_id' => self::COMPANY, 'name' => 'Old Driver '.random_int(100, 999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function writes(): array
    {
        return [
            'create a vehicle'      => ['postJson',   '/api/transport/vehicles'],
            'edit a vehicle'        => ['putJson',    '/api/transport/vehicles/{v}'],
            'move a vehicle status' => ['patchJson',  '/api/transport/vehicles/{v}/status'],
            'delete a vehicle'      => ['deleteJson', '/api/transport/vehicles/{v}'],
            'edit a driver'         => ['putJson',    '/api/transport/drivers/{d}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writes')]
    public function test_the_write_is_refused_with_a_sentence_that_says_where_to_go(string $verb, string $uri): void
    {
        $this->actAs('admin');
        $uri = str_replace(['{v}', '{d}'], [$this->legacyVehicleId(), $this->legacyDriverId()], $uri);

        $response = $this->{$verb}($uri, ['registration_number' => 'MH01NEW01', 'status' => 'available']);

        $response->assertStatus(409);

        $message = (string) $response->json('message');

        $this->assertNotSame('', $message, 'The refusal said nothing at all.');

        // The whole point of refusing in the controller rather than removing
        // the route. A 404 is not an acceptable answer here.
        $this->assertStringContainsString('Fleet', $message,
            "The refusal does not say where to go. It read: \"{$message}\". A dispatcher with a "
            .'stale form open needs the next step, not just a closed door — that is the D-136 '
            .'lesson, where a correct refusal with an unreadable message was half a guard.');

        $this->assertStringContainsString('history', $message,
            'The refusal does not say the records are still there, so it reads as though data was lost.');
    }

    /**
     * The assertion that replaces the permission matrix's write rows.
     *
     * Stronger than the matrix was: the matrix said who may write, and this
     * says nobody may — including the two roles that bypass most things.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('writes')]
    public function test_no_role_bypasses_it_including_owner_and_admin(string $verb, string $uri): void
    {
        foreach (['owner', 'admin', 'staff'] as $role) {
            $user = $this->actAs($role);
            $target = str_replace(['{v}', '{d}'], [$this->legacyVehicleId(), $this->legacyDriverId()], $uri);

            $status = $this->{$verb}($target, ['registration_number' => 'MH01NEW'.random_int(10, 99)])->status();

            $this->assertNotContains($status, [200, 201],
                "Role `{$role}` wrote to a read-only master ({$verb} {$uri}). The ruling is that "
                .'creation moved to Fleet; a role that can still write here is a second writer '
                .'into another developers data, which is the D-300 the ruling exists to avoid.');

            $user->delete();
        }
    }

    public function test_the_reads_still_work_because_the_screens_show_history(): void
    {
        $this->actAs('admin');
        $id = $this->legacyVehicleId();

        $this->getJson('/api/transport/vehicles')->assertOk();
        $this->getJson('/api/transport/vehicles/'.$id)->assertOk();
        $this->getJson('/api/transport/vehicles/status-counts')->assertOk();
    }
}
