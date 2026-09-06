<?php

namespace Tests\Feature\Medical;

use App\Models\Medical\GeneralMedical;
use App\Models\Medical\MedicalDoctorProfile;
use App\Models\Medical\MedicalVisitor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reading back what the doctor portal recorded.
 *
 * The portal could examine internal staff, client contacts and site visitors,
 * and those examinations went into `general_medicals` — where nothing read
 * them. No register, no report, no page that opened one. A record nobody can
 * see is not a record; it looked like a working feature from the doctor's side
 * and did not exist from everywhere else.
 *
 * These tests pin the way in, and the two things that made the register
 * unusable even to the doctor who wrote it.
 */
class GeneralMedicalAdminTest extends TestCase
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

    private function admin(): User
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(5).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function doctor(): User
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Dr Rao', 'role' => 'doctor',
            'email' => 'doc-'.Str::random(5).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        MedicalDoctorProfile::create([
            'tenant_id' => self::TENANT, 'user_id' => $user->id, 'license_no' => 'MH-1', 'is_active' => true,
        ]);

        return $user;
    }

    private function visitor(string $name): MedicalVisitor
    {
        return MedicalVisitor::create(['tenant_id' => self::TENANT, 'name' => $name]);
    }

    private function medical(int $subjectId, array $attributes = [], int $tenantId = self::TENANT): GeneralMedical
    {
        return GeneralMedical::create([
            'tenant_id'      => $tenantId,
            'subject_type'   => GeneralMedical::SUBJECT_VISITOR,
            'subject_id'     => $subjectId,
            'exam_date'      => '2026-09-01',
            'valid_until'    => '2027-09-01',
            'fitness_status' => 'Fit',
            'qc_status'      => 'Pending',
            'certificate_no' => 'GM-'.Str::random(5),
            ...$attributes,
        ]);
    }

    /* ── The register exists at all ─────────────────────────────────────── */

    public function test_an_admin_can_list_examinations_of_people_who_have_no_vendor(): void
    {
        $visitor = $this->visitor('Anita Desai');
        $this->medical($visitor->id);
        $this->admin();

        $body = $this->getJson('/api/medical/general')->assertOk()->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame('Anita Desai', $body['data'][0]['person'], 'the row names the person, not just an id');
        $this->assertSame('Site visitors', $body['data'][0]['audience_label']);
    }

    public function test_a_row_carries_how_many_findings_it_holds(): void
    {
        // So a register can be triaged without opening every record.
        $visitor = $this->visitor('Ravi');
        $this->medical($visitor->id, ['bp_systolic' => 168, 'bp_diastolic' => 104]);
        $this->admin();

        $row = $this->getJson('/api/medical/general')->assertOk()->json('data.0');

        $this->assertSame(1, $row['finding_count']);
    }

    public function test_one_examination_can_be_opened_in_full(): void
    {
        $visitor = $this->visitor('Ravi');
        $record  = $this->medical($visitor->id, ['bp_systolic' => 168, 'bp_diastolic' => 104]);
        $this->admin();

        $data = $this->getJson("/api/medical/general/{$record->id}")->assertOk()->json('data');

        $this->assertSame('Ravi', $data['person']['name']);
        $this->assertSame('bp_stage2', $data['findings'][0]['key']);
        $this->assertCount(1, $data['history'], 'their other examinations sit beside it');
        $this->assertTrue($data['history'][0]['is_current']);
    }

    public function test_the_report_says_what_people_share(): void
    {
        $a = $this->visitor('One');
        $b = $this->visitor('Two');
        $c = $this->visitor('Three');
        $this->medical($a->id, ['hearing' => 'Impaired']);
        $this->medical($b->id, ['hearing' => 'Impaired']);
        $this->medical($c->id, ['hearing' => 'Normal']);
        $this->admin();

        $report = $this->getJson('/api/medical/general/report')->assertOk()->json('data');

        $shared = collect($report['findings']['groups'])->firstWhere('key', 'hearing');
        $this->assertSame(2, $shared['count']);
        $this->assertTrue($shared['shared']);
        $this->assertSame(3, $report['totals']['people']);
    }

    public function test_the_report_counts_a_person_once_however_often_they_were_seen(): void
    {
        // Otherwise somebody examined quarterly looks like four people with the
        // same condition, and every shared-finding count is inflated.
        $visitor = $this->visitor('Seen often');
        $this->medical($visitor->id, ['exam_date' => '2026-03-01', 'hearing' => 'Impaired']);
        $this->medical($visitor->id, ['exam_date' => '2026-06-01', 'hearing' => 'Impaired']);
        $this->medical($visitor->id, ['exam_date' => '2026-09-01', 'hearing' => 'Impaired']);
        $this->admin();

        $report = $this->getJson('/api/medical/general/report')->assertOk()->json('data');

        $this->assertSame(3, $report['totals']['examinations']);
        $this->assertSame(1, $report['totals']['people']);
        $this->assertSame(1, collect($report['findings']['groups'])->firstWhere('key', 'hearing')['count']);
    }

    /* ── Filtering ──────────────────────────────────────────────────────── */

    public function test_searching_by_name_pages_correctly(): void
    {
        // The name lives in another table, so it cannot be a plain WHERE — and
        // filtering the page after fetching it gives short pages and a total
        // that disagrees with them.
        foreach (range(1, 60) as $i) {
            $this->medical($this->visitor('Filler '.$i)->id);
        }
        $wanted = $this->visitor('Distinctive Person');
        $this->medical($wanted->id);
        $this->admin();

        $body = $this->getJson('/api/medical/general?q=Distinctive')->assertOk()->json();

        $this->assertSame(1, $body['meta']['total']);
        $this->assertCount(1, $body['data']);
        $this->assertSame('Distinctive Person', $body['data'][0]['person']);
    }

    public function test_an_unknown_audience_filter_matches_nothing_rather_than_everything(): void
    {
        // A typo in a filter must never widen what comes back.
        $this->medical($this->visitor('Someone')->id);
        $this->admin();

        $body = $this->getJson('/api/medical/general?audience=nonsense')->assertOk()->json();

        $this->assertSame(0, $body['meta']['total']);
    }

    public function test_the_register_is_paged_and_reports_its_total(): void
    {
        foreach (range(1, 60) as $i) {
            $this->medical($this->visitor('Person '.$i)->id);
        }
        $this->admin();

        $body = $this->getJson('/api/medical/general')->assertOk()->json();

        $this->assertSame(60, $body['meta']['total']);
        $this->assertCount(50, $body['data']);
        $this->assertSame(2, $body['meta']['pages']);
    }

    /* ── Scoping ────────────────────────────────────────────────────────── */

    public function test_another_workspace_is_never_listed(): void
    {
        (new Tenant)->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $theirs = MedicalVisitor::create(['tenant_id' => 2, 'name' => 'Theirs']);
        $theirRecord = $this->medical($theirs->id, [], 2);

        $this->medical($this->visitor('Mine')->id);
        $this->admin();

        $body = $this->getJson('/api/medical/general')->assertOk()->json();
        $this->assertCount(1, $body['data']);
        $this->assertSame('Mine', $body['data'][0]['person']);

        $this->getJson("/api/medical/general/{$theirRecord->id}")->assertNotFound();
    }

    public function test_a_doctor_cannot_read_the_admin_register(): void
    {
        // Admin-only: these are examinations of employees and named visitors,
        // and a doctor reads their OWN work through the portal.
        $this->medical($this->visitor('Someone')->id);
        Sanctum::actingAs($this->doctor());

        $this->getJson('/api/medical/general')->assertForbidden();
    }

    /* ── The two defects that made the register unusable ────────────────── */

    public function test_a_declared_condition_survives_being_saved(): void
    {
        // It did not. `medical_history` was cast to array on both vendor
        // registers and not on this one, so an array was written to a text
        // column as the literal word "Array" — every condition, surgery and
        // habit declared by internal staff, clients and visitors was lost.
        $record = $this->medical($this->visitor('Ravi')->id, [
            'medical_history' => ['conditions' => ['Diabetes'], 'habits' => ['Tobacco']],
        ]);

        $this->assertSame(['Diabetes'], $record->fresh()->medical_history['conditions']);
    }

    public function test_a_doctor_can_read_back_their_own_general_examinations(): void
    {
        // "My examinations" 404ed the moment the audience was switched away
        // from a vendor side: the two vendor registers had this endpoint and
        // the three general audiences did not, so the doctor could file an
        // examination and never see it again.
        $doctor = $this->doctor();
        $visitor = $this->visitor('Anita');
        $this->medical($visitor->id, ['doctor_user_id' => $doctor->id]);
        Sanctum::actingAs($doctor);

        $rows = $this->getJson('/api/doctor/visitor/examinations')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Anita', $rows[0]['worker']['name']);
    }

    public function test_a_doctor_sees_only_their_own_examinations(): void
    {
        $mine   = $this->doctor();
        $theirs = $this->doctor();
        $this->medical($this->visitor('Mine')->id, ['doctor_user_id' => $mine->id]);
        $this->medical($this->visitor('Theirs')->id, ['doctor_user_id' => $theirs->id]);
        Sanctum::actingAs($mine);

        $rows = $this->getJson('/api/doctor/visitor/examinations')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Mine', $rows[0]['worker']['name']);
    }

    public function test_the_dashboard_summary_covers_all_five_audiences(): void
    {
        // The doctor dashboard reported the two vendor sides and nothing else,
        // so switching the audience to internal staff, clients or visitors
        // changed nothing on screen — which is indistinguishable from a switch
        // that does not work, and is what it was reported as.
        $doctor  = $this->doctor();
        $visitor = $this->visitor('Anita');
        $this->medical($visitor->id, ['doctor_user_id' => $doctor->id]);
        Sanctum::actingAs($doctor);

        $summary = $this->getJson('/api/doctor/summary')->assertOk()->json('data');

        $named = array_column($summary['modules'], 'module');
        $this->assertSame(['tpv', 'purchase', 'internal', 'client', 'visitor'], $named);

        $visitors = collect($summary['modules'])->firstWhere('module', 'visitor');
        $this->assertSame(1, $visitors['examinations']);
        $this->assertSame(1, $summary['total_examinations'], 'and it counts towards the totals');
    }

    public function test_a_doctor_can_group_a_selection_of_people(): void
    {
        $doctor = $this->doctor();
        $a = $this->visitor('One');
        $b = $this->visitor('Two');
        $this->medical($a->id, ['doctor_user_id' => $doctor->id, 'colour_vision' => 'Deficient']);
        $this->medical($b->id, ['doctor_user_id' => $doctor->id, 'colour_vision' => 'Deficient']);
        Sanctum::actingAs($doctor);

        $data = $this->postJson('/api/doctor/visitor/group-findings', ['ids' => [$a->id, $b->id]])
            ->assertOk()->json('data');

        $group = collect($data['groups'])->firstWhere('key', 'colour_vision');
        $this->assertSame(2, $group['count']);
        $this->assertTrue($group['shared']);
    }

    public function test_grouping_never_reaches_another_workspace(): void
    {
        (new Tenant)->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $theirs = MedicalVisitor::create(['tenant_id' => 2, 'name' => 'Theirs']);
        $this->medical($theirs->id, ['colour_vision' => 'Deficient'], 2);

        $doctor = $this->doctor();
        Sanctum::actingAs($doctor);

        $data = $this->postJson('/api/doctor/visitor/group-findings', ['ids' => [$theirs->id]])
            ->assertOk()->json('data');

        $this->assertSame([], $data['groups']);
        $this->assertSame(0, $data['examined']);
    }
}
