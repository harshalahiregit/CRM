<?php

namespace Tests\Feature\Transport;

use App\Domains\Fleet\Services\DriverRegistrationService;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Transport\TransportDocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase C — a driver, from the app, manages their OWN profile and documents.
 *
 * The whole point is SCOPE_OWN: the signed-in driver is resolved from their
 * login, never from an id in the path, so there is nothing to point at someone
 * else. A non-driver login is refused. Uploaded documents are PENDING until the
 * office verifies them, and only then is the driver cleared to drive.
 */
class DriverSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Alpha Transport', 'slug' => 'alpha',
            'subdomain' => 'alpha', 'status' => 'active',
        ])->save();
    }

    /** Register a driver and approve them, returning the created login. */
    private function approvedDriver(): User
    {
        $admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin@alpha.test', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $svc = app(DriverRegistrationService::class);
        $svc->register(self::TENANT, [
            'name' => 'Ramesh Kumar', 'email' => 'ramesh@alpha.test',
            'password' => 'secret123', 'phone' => '98765 43210', 'licence_number' => 'MH0120110012345',
        ]);
        $reg = \DB::table('driver_registrations')->where('email', 'ramesh@alpha.test')->first();
        $svc->approve(self::TENANT, $reg->id, $admin->id);

        return User::where('email', 'ramesh@alpha.test')->firstOrFail();
    }

    public function test_a_driver_sees_their_own_profile_and_is_blocked_until_documents_are_verified(): void
    {
        $driver = $this->approvedDriver();

        $res = $this->actingAs($driver, 'sanctum')->getJson('/api/v1/me/driver');
        $res->assertOk()
            ->assertJsonPath('data.profile.name', 'Ramesh Kumar')
            ->assertJsonPath('data.profile.licence_number', 'MH0120110012345')
            // No documents on file → not cleared to drive.
            ->assertJsonPath('data.eligibility.eligible', false);

        $this->assertNotEmpty($res->json('data.eligibility.blocking'));
    }

    public function test_a_driver_can_upload_a_document_and_it_files_as_pending(): void
    {
        Storage::fake('local');
        $driver = $this->approvedDriver();

        $upload = $this->actingAs($driver, 'sanctum')->postJson('/api/v1/me/driver/documents', [
            'document_type' => TransportDocumentType::DRIVING_LICENSE,
            'document_number' => 'MH0120110012345',
            'file' => UploadedFile::fake()->create('licence.pdf', 200, 'application/pdf'),
        ]);
        $upload->assertCreated();

        // It shows up on the driver's own list, and they are still not cleared —
        // a pending document does not clear the gate.
        $after = $this->actingAs($driver, 'sanctum')->getJson('/api/v1/me/driver');
        $after->assertOk();
        $this->assertCount(1, $after->json('data.documents'));
        $after->assertJsonPath('data.eligibility.eligible', false);
    }

    public function test_upload_requires_a_file(): void
    {
        $driver = $this->approvedDriver();
        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/v1/me/driver/documents', ['document_type' => TransportDocumentType::DRIVING_LICENSE])
            ->assertStatus(422);
    }

    public function test_a_non_driver_login_is_refused(): void
    {
        $office = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Office', 'role' => 'staff',
            'email' => 'office@alpha.test', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->actingAs($office, 'sanctum')->getJson('/api/v1/me/driver')->assertStatus(403);
    }
}
