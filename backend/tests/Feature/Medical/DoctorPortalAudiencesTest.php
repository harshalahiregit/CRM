<?php

namespace Tests\Feature\Medical;

use App\Models\Medical\GeneralMedical;
use App\Models\Medical\MedicalDoctorProfile;
use App\Models\Medical\MedicalVisitor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The doctor portal serves everyone, and refuses to issue without its proof.
 *
 * Two things are pinned here.
 *
 * The portal could examine TPV and Purchase workers only, so the internal team,
 * a client's people and anyone signing in at the gate had nowhere to be
 * examined at all — the portal was "for all personnel" in name only.
 *
 * And the three things that make a certificate evidence rather than an
 * assertion — where it happened, who signed it, and a photograph taken at the
 * time — were all optional. A certificate could be issued with none of them,
 * and nobody would notice until it was challenged.
 */
class DoctorPortalAudiencesTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    /** A 1×1 PNG, which is all the endpoint needs to see. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant)->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function doctor(bool $licensed = true): User
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Dr Rao', 'role' => 'doctor',
            'email' => 'doc-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        MedicalDoctorProfile::create([
            'tenant_id' => self::TENANT, 'user_id' => $user->id,
            'license_no' => $licensed ? 'MH-123456' : null, 'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function staff(string $name = 'Priya'): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'role' => 'staff',
            'email' => strtolower($name).'-'.Str::random(4).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active', 'department' => 'Projects',
        ]);
    }

    /** Everything the endpoint now insists on. */
    private function validExam(array $overrides = []): array
    {
        return array_merge([
            'exam_date'      => now()->toDateString(),
            'fitness_status' => 'Fit',
            'signature_data' => self::PNG,
            'capture_photo'  => self::PNG,
            'geo_location'   => '19.0760,72.8777',
        ], $overrides);
    }

    /* ── Everyone can be examined ───────────────────────────────────────── */

    public function test_an_internal_team_member_can_be_examined(): void
    {
        $staff = $this->staff();
        $this->doctor();

        $people = $this->getJson('/api/doctor/internal/people')->assertOk()->json('data');
        $this->assertContains($staff->id, array_column($people, 'id'));

        $this->postJson("/api/doctor/internal/people/{$staff->id}/examination", $this->validExam())
            ->assertCreated();

        $this->assertDatabaseHas('general_medicals', [
            'subject_type' => GeneralMedical::SUBJECT_USER, 'subject_id' => $staff->id,
        ]);
    }

    public function test_a_client_contact_can_be_examined(): void
    {
        $clientId = DB::table('clients')->insertGetId([
            'tenant_id' => self::TENANT, 'company' => 'Acme', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $contactId = DB::table('client_contacts')->insertGetId([
            'tenant_id' => self::TENANT, 'client_id' => $clientId,
            'first_name' => 'Anil', 'last_name' => 'Kumar', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->doctor();

        $this->postJson("/api/doctor/client/people/{$contactId}/examination", $this->validExam())
            ->assertCreated();

        $this->assertDatabaseHas('general_medicals', [
            'subject_type' => GeneralMedical::SUBJECT_CLIENT, 'subject_id' => $contactId,
        ]);
    }

    public function test_a_site_visitor_can_be_registered_and_examined(): void
    {
        // A visitor is by definition somebody the system has never met, so there
        // is nothing to point an examination at until they are registered.
        $this->doctor();

        $visitorId = $this->postJson('/api/doctor/visitors', [
            'name' => 'Ramesh Patil', 'phone' => '9876543210',
            'company' => 'Site Inspection Ltd', 'purpose' => 'Safety audit',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/doctor/visitor/people/{$visitorId}/examination", $this->validExam())
            ->assertCreated();

        $this->assertDatabaseHas('general_medicals', [
            'subject_type' => GeneralMedical::SUBJECT_VISITOR, 'subject_id' => $visitorId,
        ]);
    }

    public function test_an_audience_nobody_offers_is_refused(): void
    {
        $this->doctor();
        $this->getJson('/api/doctor/martians/people')->assertStatus(404);
    }

    /* ── The proof is mandatory ─────────────────────────────────────────── */

    public function test_an_examination_without_a_signature_is_refused(): void
    {
        $staff = $this->staff();
        $this->doctor();

        $this->postJson("/api/doctor/internal/people/{$staff->id}/examination",
            $this->validExam(['signature_data' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('signature_data');
    }

    public function test_an_examination_without_a_camera_photo_is_refused(): void
    {
        $staff = $this->staff();
        $this->doctor();

        $this->postJson("/api/doctor/internal/people/{$staff->id}/examination",
            $this->validExam(['capture_photo' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('capture_photo');
    }

    public function test_an_examination_without_a_location_is_refused(): void
    {
        $staff = $this->staff();
        $this->doctor();

        $this->postJson("/api/doctor/internal/people/{$staff->id}/examination",
            $this->validExam(['geo_location' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('geo_location');
    }

    public function test_an_unreadable_location_is_refused(): void
    {
        // "denied", "unknown" or an empty pair would otherwise be stored as a
        // location that means nothing, which is worse than no location at all.
        $staff = $this->staff();
        $this->doctor();

        $this->postJson("/api/doctor/internal/people/{$staff->id}/examination",
            $this->validExam(['geo_location' => 'unavailable']))
            ->assertStatus(422)->assertJsonValidationErrors('geo_location');
    }

    public function test_the_stored_record_carries_the_proof(): void
    {
        $staff = $this->staff();
        $doctor = $this->doctor();

        $this->postJson("/api/doctor/internal/people/{$staff->id}/examination", $this->validExam())
            ->assertCreated();

        $record = GeneralMedical::first();
        $this->assertNotEmpty($record->signature_path);
        $this->assertNotEmpty($record->capture_photo_path);
        $this->assertSame('19.0760,72.8777', $record->geo_location);
        // Taken from the request, never from the payload — a caller that could
        // supply its own IP could forge the provenance of a certificate.
        $this->assertNotEmpty($record->system_ip);
        $this->assertSame($doctor->id, $record->doctor_user_id);
        $this->assertSame('MH-123456', $record->doctor_license_no);
        $this->assertNotEmpty($record->certificate_no);
    }

    public function test_an_unlicensed_doctor_still_cannot_issue(): void
    {
        $staff = $this->staff();
        $this->doctor(licensed: false);

        $this->postJson("/api/doctor/internal/people/{$staff->id}/examination", $this->validExam())
            ->assertStatus(422);
    }

    /* ── History ────────────────────────────────────────────────────────── */

    public function test_the_history_reads_newest_first_and_is_capped(): void
    {
        // The timeline on a profile shows the recent examinations; somebody
        // examined monthly for a decade must not send 120 records to it.
        $staff = $this->staff();
        $this->doctor();

        foreach (range(1, 12) as $i) {
            $this->postJson("/api/doctor/internal/people/{$staff->id}/examination",
                $this->validExam(['exam_date' => now()->subDays($i)->toDateString()]))->assertCreated();
        }

        $history = $this->getJson("/api/doctor/internal/people/{$staff->id}")
            ->assertOk()->json('data.history');

        $this->assertCount(10, $history, 'the timeline is capped at ten');
        $this->assertSame(now()->subDay()->toDateString(),
            substr($history[0]['exam_date'], 0, 10), 'newest first');
    }

    public function test_a_second_examination_the_same_day_corrects_the_first(): void
    {
        // Same person, same day, still unreviewed: a correction to what was just
        // filed, not a second visit — otherwise a typo becomes a duplicate.
        $staff = $this->staff();
        $this->doctor();

        $this->postJson("/api/doctor/internal/people/{$staff->id}/examination",
            $this->validExam(['fitness_status' => 'Unfit']))->assertCreated();
        $this->postJson("/api/doctor/internal/people/{$staff->id}/examination",
            $this->validExam(['fitness_status' => 'Fit']))->assertCreated();

        $this->assertSame(1, GeneralMedical::count());
        $this->assertSame('Fit', GeneralMedical::first()->fitness_status);
    }

    /* ── Scoping ────────────────────────────────────────────────────────── */

    public function test_another_workspaces_person_cannot_be_examined(): void
    {
        (new Tenant)->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $outsider = User::create([
            'tenant_id' => 2, 'name' => 'Outsider', 'role' => 'staff',
            'email' => 'out@t2.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->doctor();

        $this->postJson("/api/doctor/internal/people/{$outsider->id}/examination", $this->validExam())
            ->assertStatus(404);
    }

    public function test_a_visitor_from_another_workspace_is_not_listed(): void
    {
        (new Tenant)->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        MedicalVisitor::create(['tenant_id' => 2, 'name' => 'Theirs']);

        $this->doctor();

        $names = array_column($this->getJson('/api/doctor/visitor/people')->assertOk()->json('data'), 'name');
        $this->assertNotContains('Theirs', $names);
    }

    /* ── Only a doctor ──────────────────────────────────────────────────── */

    public function test_a_non_doctor_cannot_reach_the_portal(): void
    {
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/doctor/internal/people')->assertForbidden();
    }
}
