<?php

namespace Tests\Feature\Medical;

use App\Models\Medical\MedicalDoctorProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Medical\MedicalEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Where medical evidence lives, and who may read it.
 *
 * The signature a doctor draws once, and the signature and camera photo taken
 * at each examination, used to be written to `storage/app/public` — served at
 * /storage/** with no authentication — under names built from `uniqid()`.
 * uniqid() is derived from the clock, not from randomness, so those names were
 * enumerable rather than secret.
 *
 * That matters more here than for most files: a downloadable doctor's signature
 * is a forgeable fitness certificate, which is the one thing this module exists
 * to prevent.
 */
class MedicalEvidencePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    /** A 1×1 PNG, as the canvas hands it over. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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

        return $user;
    }

    /* ── Where it is written ────────────────────────────────────────────── */

    public function test_a_signature_is_written_to_the_private_disk_not_the_public_one(): void
    {
        $doctor = $this->doctor();
        Sanctum::actingAs($doctor);

        $this->putJson('/api/doctor/me', ['signature_data' => self::PNG])->assertOk();

        $path = $doctor->fresh()->doctorProfile->signature_path;

        $this->assertNotNull($path);
        $this->assertTrue(Storage::disk(MedicalEvidence::DISK)->exists($path), 'it belongs on the private disk');
        $this->assertFalse(Storage::disk('public')->exists($path), 'and must never be on the served one');
    }

    public function test_the_filename_is_random_rather_than_time_derived(): void
    {
        // uniqid() is microtime in hex: knowing roughly when a record was written
        // narrows it to a walkable space. Two signatures written back to back
        // must not produce neighbouring names.
        $a = MedicalEvidence::put(self::PNG, 'medical/test/');
        $b = MedicalEvidence::put(self::PNG, 'medical/test/');

        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('#^medical/test/[A-Za-z0-9]{40}\.png$#', $a);

        // Nothing in common beyond the prefix — a time-derived pair would share
        // almost every leading character.
        $left  = substr(basename($a, '.png'), 0, 8);
        $right = substr(basename($b, '.png'), 0, 8);
        $this->assertNotSame($left, $right);
    }

    public function test_rubbish_in_makes_no_file(): void
    {
        // A malformed capture must leave the column empty rather than pointing
        // the record at a file full of nothing.
        $this->assertNull(MedicalEvidence::put('not-a-data-url', 'medical/test/'));
        $this->assertNull(MedicalEvidence::put(null, 'medical/test/'));
    }

    /* ── Who may read it ────────────────────────────────────────────────── */

    public function test_a_doctor_can_read_their_own_signature(): void
    {
        $doctor = $this->doctor();
        Sanctum::actingAs($doctor);
        $this->putJson('/api/doctor/me', ['signature_data' => self::PNG])->assertOk();

        $this->get('/api/doctor/me/signature')->assertOk();
    }

    public function test_a_doctor_never_receives_another_doctors_signature(): void
    {
        // The route reads the CALLER's own profile, so there is no id to tamper
        // with — which is the point of scoping it this way rather than by path.
        $mine = $this->doctor();
        $theirs = $this->doctor();
        Sanctum::actingAs($theirs);
        $this->putJson('/api/doctor/me', ['signature_data' => self::PNG])->assertOk();

        Sanctum::actingAs($mine);
        $this->get('/api/doctor/me/signature')->assertNotFound();
    }

    public function test_a_signed_out_caller_gets_nothing(): void
    {
        $doctor = $this->doctor();
        Sanctum::actingAs($doctor);
        $this->putJson('/api/doctor/me', ['signature_data' => self::PNG])->assertOk();

        // The whole reason for the move: no session, no signature.
        app('auth')->forgetGuards();
        $this->getJson('/api/doctor/me/signature')->assertUnauthorized();
    }

    public function test_an_unknown_kind_is_not_a_way_to_ask_for_files(): void
    {
        Sanctum::actingAs($this->doctor());

        $this->get('/api/doctor/me/licence')->assertNotFound();
    }

    /* ── The guard on stored paths ──────────────────────────────────────── */

    public function test_a_path_that_climbs_out_of_the_medical_folders_is_refused(): void
    {
        $this->assertFalse(MedicalEvidence::isSafe('../../.env'));
        $this->assertFalse(MedicalEvidence::isSafe('/etc/passwd'));
        $this->assertFalse(MedicalEvidence::isSafe('C:/Windows/win.ini'));
        $this->assertTrue(MedicalEvidence::isSafe('medical/doctors/signature_abc.png'));
    }

    /* ── Records written before the move ────────────────────────────────── */

    public function test_a_file_left_on_the_old_disk_is_still_found(): void
    {
        // The migration moves them, but a certificate that quietly loses its
        // signature because one file was missed is worse than a fallback.
        Storage::disk('public')->put('medical/legacy/old.png', 'bytes');

        $this->assertSame('public', MedicalEvidence::diskFor('medical/legacy/old.png'));
        $this->assertNotNull(MedicalEvidence::absolutePath('medical/legacy/old.png'));
    }

    public function test_a_path_with_no_file_behind_it_resolves_to_nothing(): void
    {
        $this->assertNull(MedicalEvidence::diskFor('medical/nowhere/missing.png'));
        $this->assertNull(MedicalEvidence::absolutePath('medical/nowhere/missing.png'));
        $this->assertNull(MedicalEvidence::absolutePath(null));
    }
}
