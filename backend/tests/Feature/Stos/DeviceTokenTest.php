<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\TelemetryRecord;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Integration\Models\TelemetryDeviceToken;
use App\Domains\Integration\Services\DeviceTokenService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-INT — one credential per GPS unit (T-07).
 *
 * The shared fleet-wide secret could not be revoked for one stolen box, and —
 * the part that actually broke things — it said nothing about WHICH COMPANY was
 * calling. Since `gps_device_id` is unique per company rather than globally, a
 * device id held by two companies could not be resolved at all and was refused
 * with 409. Those vehicles could not receive telemetry.
 */
class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;
    private const OTHER   = 2;
    private const SHARED_SECRET = 'legacy-fleet-wide-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['stos.ingest.token' => self::SHARED_SECRET]);

        foreach ([self::COMPANY, self::OTHER] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => "Co{$id}", 'slug' => "co{$id}",
                'subdomain' => "co{$id}", 'status' => 'active',
            ])->save();
        }
    }

    private function user(int $company = self::COMPANY): User
    {
        return User::create([
            'tenant_id' => $company, 'name' => 'Ops', 'role' => 'admin',
            'email' => 'ops-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vehicle(string $device, int $company = self::COMPANY): Vehicle
    {
        return Vehicle::create([
            'company_id' => $company,
            'registration_number' => 'MH'.random_int(10, 99).'AB'.random_int(1000, 9999),
            'vehicle_type' => 'reefer', 'gps_device_id' => $device,
        ]);
    }

    private function issue(string $device, int $company = self::COMPANY): array
    {
        return app(DeviceTokenService::class)->issue($company, $device, 'Unit '.$device);
    }

    private function ping(string $device, ?string $token)
    {
        return $this->withHeaders($token === null ? [] : ['X-Device-Token' => $token])
            ->postJson('/api/v1/telemetry/ingest', [
                'device_id'   => $device,
                'latitude'    => '19.07609500',
                'longitude'   => '72.87765800',
                'speed'       => 40,
                'ignition'    => true,
                'recorded_at' => now()->subMinutes(2)->toDateTimeString(),
            ]);
    }

    /* ── The thing this was built to fix ────────────────────────── */

    public function test_two_companies_sharing_a_device_id_can_both_report(): void
    {
        // Device ids are chosen by whoever fits the hardware; units come from
        // the same batches, so this collision is ordinary, not exotic.
        $ours   = $this->vehicle('DEV-SHARED', self::COMPANY);
        $theirs = $this->vehicle('DEV-SHARED', self::OTHER);

        $ourToken   = $this->issue('DEV-SHARED', self::COMPANY)['plain'];
        $theirToken = $this->issue('DEV-SHARED', self::OTHER)['plain'];

        $this->ping('DEV-SHARED', $ourToken)->assertCreated();
        $this->ping('DEV-SHARED', $theirToken)->assertCreated();

        // Each ping landed on its OWN company's truck. Under the shared secret
        // neither could be resolved at all.
        $this->assertSame(1, TelemetryRecord::where('vehicle_id', $ours->id)->count());
        $this->assertSame(1, TelemetryRecord::where('vehicle_id', $theirs->id)->count());
    }

    public function test_the_shared_secret_still_cannot_resolve_an_ambiguous_device(): void
    {
        $this->vehicle('DEV-SHARED', self::COMPANY);
        $this->vehicle('DEV-SHARED', self::OTHER);

        $response = $this->ping('DEV-SHARED', self::SHARED_SECRET);

        // Unchanged and correct: guessing would write a position and a
        // temperature onto another company's truck. The message now says what
        // fixes it rather than only that it failed.
        $response->assertStatus(409);
        $this->assertStringContainsString('own device token', $response->json('message'));
    }

    public function test_a_token_cannot_report_for_another_companys_device(): void
    {
        $this->vehicle('DEV-ONLY-THEIRS', self::OTHER);
        $ourToken = $this->issue('DEV-ONLY-THEIRS', self::COMPANY)['plain'];

        // Our credential, their truck. Scoped resolution finds nothing in our
        // company rather than reaching across the boundary.
        $this->ping('DEV-ONLY-THEIRS', $ourToken)->assertStatus(404);
        $this->assertSame(0, TelemetryRecord::count());
    }

    /* ── The credential itself ──────────────────────────────────── */

    public function test_a_valid_token_is_accepted_and_the_ping_lands(): void
    {
        $vehicle = $this->vehicle('DEV-0001');
        $token = $this->issue('DEV-0001')['plain'];

        $this->ping('DEV-0001', $token)->assertCreated();
        $this->assertSame(1, TelemetryRecord::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_the_plaintext_is_never_stored(): void
    {
        $this->vehicle('DEV-0002');
        $issued = $this->issue('DEV-0002');

        // A stolen database must not yield working credentials for a fleet.
        $this->assertDatabaseMissing('telemetry_device_tokens', ['token_hash' => $issued['plain']]);
        $this->assertSame(
            TelemetryDeviceToken::hash($issued['plain']),
            TelemetryDeviceToken::first()->token_hash
        );
    }

    public function test_the_hash_never_leaves_the_server(): void
    {
        $this->vehicle('DEV-0003');
        $this->issue('DEV-0003');

        $body = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/devices/tokens')->assertOk()->getContent();

        $this->assertStringNotContainsString('token_hash', $body);
    }

    public function test_a_token_carries_a_recognisable_prefix(): void
    {
        $this->vehicle('DEV-0004');

        // So somebody who finds it in a log or a support ticket can tell what
        // it is and revoke it, instead of it reading as an anonymous blob.
        $this->assertStringStartsWith(TelemetryDeviceToken::PREFIX, $this->issue('DEV-0004')['plain']);
    }

    public function test_using_a_token_stamps_it_without_touching_updated_at(): void
    {
        $this->vehicle('DEV-0005');
        $token = $this->issue('DEV-0005');
        $before = $token['token']->updated_at;

        $this->ping('DEV-0005', $token['plain'])->assertCreated();

        $fresh = TelemetryDeviceToken::find($token['token']->id);
        $this->assertNotNull($fresh->last_used_at);
        // A usage stamp is not an edit of the credential.
        $this->assertEquals($before, $fresh->updated_at);
    }

    /* ── Revocation and rotation ────────────────────────────────── */

    public function test_a_revoked_token_stops_working_immediately(): void
    {
        $this->vehicle('DEV-STOLEN');
        $issued = $this->issue('DEV-STOLEN');

        $this->ping('DEV-STOLEN', $issued['plain'])->assertCreated();

        app(DeviceTokenService::class)->revoke($issued['token']->id, self::COMPANY, 'Box stolen with the truck');

        // The whole point: one box costs one box, not a fleet-wide re-flash.
        $this->ping('DEV-STOLEN', $issued['plain'])->assertStatus(401);
        $this->assertSame(1, TelemetryRecord::count());
    }

    public function test_revoking_one_unit_leaves_every_other_unit_reporting(): void
    {
        $this->vehicle('DEV-A');
        $this->vehicle('DEV-B');
        $a = $this->issue('DEV-A');
        $b = $this->issue('DEV-B');

        app(DeviceTokenService::class)->revoke($a['token']->id, self::COMPANY);

        $this->ping('DEV-A', $a['plain'])->assertStatus(401);
        $this->ping('DEV-B', $b['plain'])->assertCreated();
    }

    public function test_a_rotation_leaves_the_old_token_live_until_it_is_revoked(): void
    {
        $this->vehicle('DEV-ROTATE');
        $old = $this->issue('DEV-ROTATE');

        $new = app(DeviceTokenService::class)->rotate($old['token']->id, self::COMPANY);

        // A unit in a tunnel cannot be re-flashed on our schedule. Revoking
        // first would make every rotation a gap in the trail.
        $this->ping('DEV-ROTATE', $old['plain'])->assertCreated();
        $this->ping('DEV-ROTATE', $new['plain'])->assertCreated();

        app(DeviceTokenService::class)->revoke($old['token']->id, self::COMPANY, 'Rotated');

        $this->ping('DEV-ROTATE', $old['plain'])->assertStatus(401);
        $this->ping('DEV-ROTATE', $new['plain'])->assertCreated();
    }

    public function test_revoking_twice_is_refused_rather_than_silently_accepted(): void
    {
        $this->vehicle('DEV-TWICE');
        $issued = $this->issue('DEV-TWICE');

        app(DeviceTokenService::class)->revoke($issued['token']->id, self::COMPANY);

        $this->actingAs($this->user())
            ->deleteJson("/api/v1/fleet/devices/tokens/{$issued['token']->id}")
            ->assertStatus(422);
    }

    /* ── The legacy secret, and the list of what still uses it ──── */

    public function test_the_shared_secret_still_works_during_the_transition(): void
    {
        $this->vehicle('DEV-LEGACY');

        // Removing it in the same change that introduces tokens would take
        // every already-flashed unit off the air at once.
        $this->ping('DEV-LEGACY', self::SHARED_SECRET)->assertCreated();
    }

    public function test_an_unknown_credential_is_refused(): void
    {
        $this->vehicle('DEV-0006');

        $this->ping('DEV-0006', 'stos_dev_not-a-real-token')->assertStatus(401);
        $this->ping('DEV-0006', null)->assertStatus(401);
        $this->assertSame(0, TelemetryRecord::count());
    }

    public function test_the_listing_names_the_units_still_on_the_shared_secret(): void
    {
        $this->vehicle('DEV-HAS-TOKEN');
        $this->vehicle('DEV-NO-TOKEN');
        $this->issue('DEV-HAS-TOKEN');

        $data = $this->actingAs($this->user())
            ->getJson('/api/v1/fleet/devices/tokens')->assertOk()->json('data');

        // The migration checklist: which boxes are still relying on one secret
        // shared across every customer.
        $this->assertSame(['DEV-NO-TOKEN'], $data['uncredentialed_devices']);
    }

    /* ── Tenancy and access ─────────────────────────────────────── */

    public function test_one_company_never_sees_anothers_tokens(): void
    {
        $this->vehicle('DEV-OURS', self::COMPANY);
        $this->vehicle('DEV-THEIRS', self::OTHER);
        $this->issue('DEV-OURS', self::COMPANY);
        $this->issue('DEV-THEIRS', self::OTHER);

        $data = $this->actingAs($this->user(self::COMPANY))
            ->getJson('/api/v1/fleet/devices/tokens')->assertOk()->json('data');

        $this->assertCount(1, $data['tokens']);
        $this->assertSame('DEV-OURS', $data['tokens'][0]['device_id']);
    }

    public function test_another_companys_token_cannot_be_revoked(): void
    {
        $this->vehicle('DEV-THEIRS', self::OTHER);
        $theirs = $this->issue('DEV-THEIRS', self::OTHER);

        $this->actingAs($this->user(self::COMPANY))
            ->deleteJson("/api/v1/fleet/devices/tokens/{$theirs['token']->id}")
            ->assertStatus(404);

        $this->assertNull(TelemetryDeviceToken::find($theirs['token']->id)->revoked_at);
    }

    public function test_issuing_for_an_unfitted_device_works_but_says_so(): void
    {
        // A fitter may credential a box before it is bolted to anything — but
        // an unknown id is more often a typo, and a typo here produces a unit
        // that silently never reports.
        $result = $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/devices/tokens', ['device_id' => 'DEV-NOT-FITTED-YET'])
            ->assertCreated()->json('data');

        $this->assertFalse($result['fitted']);
        $this->assertStringContainsString('No vehicle in this fleet', $result['notice']);
    }

    public function test_the_issue_response_warns_that_the_token_is_shown_once(): void
    {
        $this->vehicle('DEV-0007');

        $result = $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/devices/tokens', ['device_id' => 'DEV-0007'])
            ->assertCreated()->json('data');

        $this->assertNotEmpty($result['plain']);
        $this->assertStringContainsString('only time this token will be shown', $result['warning']);
    }
}
