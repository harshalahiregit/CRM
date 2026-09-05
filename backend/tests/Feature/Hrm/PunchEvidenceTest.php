<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A punch keeps what it was made with.
 *
 * The app sends coordinates and a selfie on every clock in and out — it refuses
 * to punch at all without the photo. The CRM validated the coordinates and threw
 * them away, wrote the selfie to disk and discarded the path, and never recorded
 * the address it came from. So the attendance register had a time and nothing to
 * support it, which is the opposite of why the app asks for a photo.
 *
 * In and out are captured separately on purpose: a clock-out happens hours later
 * and somewhere else, and "did they leave from site?" is the question this data
 * exists to answer.
 */
class PunchEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private HrEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'punch-t', 'status' => 'active']);

        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Priya', 'email' => 'priya@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        $this->employee = HrEmployee::create([
            'tenant_id' => $tenant->id, 'employee_code' => 'SNE-1', 'name' => 'Priya',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $user->id, 'app_login_enabled' => true,
        ]);

        Sanctum::actingAs($user);
    }

    private function punch(string $type): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/Hrm/clock-in-out', [
            'workspace_id' => '1',
            'type'         => $type,
            'latitude'     => $type === 'clockin' ? '19.0760' : '18.5204',
            'longitude'    => $type === 'clockin' ? '72.8777' : '73.8567',
            'selfie'       => UploadedFile::fake()->image("{$type}.jpg"),
        ]);
    }

    public function test_a_clock_in_keeps_its_coordinates_photo_and_address(): void
    {
        $this->punch('clockin')->assertOk()->assertJsonPath('status', 1);

        $day = HrAttendance::where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertSame('19.0760', $day->check_in_latitude);
        $this->assertSame('72.8777', $day->check_in_longitude);
        $this->assertNotNull($day->check_in_selfie, 'The selfie path must be kept, not just the file.');
        $this->assertNotNull($day->check_in_ip);
    }

    /** The clock-out is a separate place at a separate time, and stored as one. */
    public function test_a_clock_out_records_its_own_place_without_touching_the_clock_in(): void
    {
        $this->punch('clockin')->assertOk();
        $this->punch('clockout')->assertOk();

        $day = HrAttendance::where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertSame('19.0760', $day->check_in_latitude, 'The clock-in location must survive the clock-out.');
        $this->assertSame('18.5204', $day->check_out_latitude);
        $this->assertNotNull($day->check_out_selfie);
        $this->assertNotSame($day->check_in_selfie, $day->check_out_selfie);
    }

    /**
     * The register receives a signed link, never the storage path.
     *
     * A path invites somebody to construct a direct URL that skips the
     * signature, and these are photographs of people's faces.
     */
    public function test_the_register_gets_a_signed_link_and_not_a_path(): void
    {
        $this->punch('clockin')->assertOk();

        $row = HrAttendance::where('employee_id', $this->employee->id)->firstOrFail()->toArray();

        $this->assertArrayNotHasKey('check_in_selfie', $row, 'The raw path must not be serialised.');
        $this->assertArrayHasKey('check_in_selfie_url', $row);
        $this->assertStringContainsString('signature=', (string) $row['check_in_selfie_url']);
    }

    public function test_the_signed_link_serves_the_photo_and_a_tampered_one_does_not(): void
    {
        $this->punch('clockin')->assertOk();

        $url = HrAttendance::where('employee_id', $this->employee->id)->firstOrFail()->check_in_selfie_url;

        $this->get($url)->assertOk();
        $this->get($url.'x')->assertForbidden();
    }

    /** A photo that cannot be written must never cost somebody their punch. */
    public function test_a_punch_without_a_selfie_still_succeeds(): void
    {
        $this->post('/api/Hrm/clock-in-out', [
            'workspace_id' => '1', 'type' => 'clockin',
            'latitude' => '19.0760', 'longitude' => '72.8777',
        ])->assertOk()->assertJsonPath('status', 1);

        $day = HrAttendance::where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertNull($day->check_in_selfie);
        $this->assertSame('19.0760', $day->check_in_latitude, 'The rest of the evidence is still kept.');
    }
}
