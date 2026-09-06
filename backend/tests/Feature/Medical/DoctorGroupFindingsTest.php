<?php

namespace Tests\Feature\Medical;

use App\Models\Medical\MedicalDoctorProfile;
use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerMedical;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reading a ticked group of vendor workers together.
 *
 * A doctor who selects fourteen people gets fourteen certificates, and fourteen
 * certificates read one at a time cannot answer the question that matters on a
 * site: is the same thing wrong with several of them? Six workers off one gang
 * with the same hearing loss is a noise-exposure problem, not six coincidences
 * — but only if somebody can see that they are six.
 */
class DoctorGroupFindingsTest extends TestCase
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

    private function worker(string $name, ?array $medical = null, int $tenantId = self::TENANT): TpvWorker
    {
        $vendor = Vendor::create([
            'tenant_id' => $tenantId, 'company_name' => 'Alpha Contractors',
            'vendor_code' => 'V-'.Str::random(6), 'status' => 'active',
        ]);

        $worker = TpvWorker::create([
            'tenant_id' => $tenantId, 'vendor_id' => $vendor->id,
            'name' => $name, 'worker_code' => 'W-'.Str::random(6),
        ]);

        if ($medical !== null) {
            TpvWorkerMedical::create([
                'tenant_id'      => $tenantId,
                'tpv_worker_id'  => $worker->id,
                'exam_date'      => '2026-09-01',
                'fitness_status' => 'Fit',
                ...$medical,
            ]);
        }

        return $worker;
    }

    public function test_workers_are_grouped_by_the_finding_they_share(): void
    {
        $a = $this->worker('Ramesh', ['hearing' => 'Impaired']);
        $b = $this->worker('Suresh', ['hearing' => 'Impaired']);
        $c = $this->worker('Mahesh', ['hearing' => 'Normal']);
        $this->doctor();

        $data = $this->postJson('/api/doctor/tpv/group-findings', ['ids' => [$a->id, $b->id, $c->id]])
            ->assertOk()->json('data');

        $shared = collect($data['groups'])->firstWhere('key', 'hearing');
        $this->assertSame(2, $shared['count']);
        $this->assertTrue($shared['shared']);
        $this->assertSame(['Ramesh', 'Suresh'], array_column($shared['people'], 'name'));
        $this->assertSame(['Mahesh'], array_column($data['clear'], 'name'));
    }

    public function test_each_person_is_named_with_their_vendor(): void
    {
        // Grouping across vendors is only useful if the answer says whose crew
        // the finding belongs to.
        $worker = $this->worker('Ramesh', ['colour_vision' => 'Deficient']);
        $this->doctor();

        $data = $this->postJson('/api/doctor/tpv/group-findings', ['ids' => [$worker->id]])
            ->assertOk()->json('data');

        $this->assertSame('Alpha Contractors', $data['groups'][0]['people'][0]['context']);
    }

    public function test_the_latest_examination_is_the_one_read(): void
    {
        // A finding that has been resolved must not still group somebody: the
        // question is what is wrong with them NOW.
        $worker = $this->worker('Ramesh', ['exam_date' => '2025-01-01', 'hearing' => 'Impaired']);
        TpvWorkerMedical::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $worker->id,
            'exam_date' => '2026-09-01', 'fitness_status' => 'Fit', 'hearing' => 'Normal',
        ]);
        $this->doctor();

        $data = $this->postJson('/api/doctor/tpv/group-findings', ['ids' => [$worker->id]])
            ->assertOk()->json('data');

        $this->assertSame([], $data['groups']);
        $this->assertSame(['Ramesh'], array_column($data['clear'], 'name'));
    }

    public function test_a_worker_never_examined_is_listed_apart_from_the_clear_ones(): void
    {
        $seen  = $this->worker('Seen', ['fitness_status' => 'Fit']);
        $never = $this->worker('Never seen');
        $this->doctor();

        $data = $this->postJson('/api/doctor/tpv/group-findings', ['ids' => [$seen->id, $never->id]])
            ->assertOk()->json('data');

        $this->assertSame(['Never seen'], array_column($data['unexamined'], 'name'));
        $this->assertSame(['Seen'], array_column($data['clear'], 'name'));
    }

    public function test_no_ids_is_an_empty_answer_not_an_error(): void
    {
        $this->doctor();

        $data = $this->postJson('/api/doctor/tpv/group-findings', ['ids' => []])->assertOk()->json('data');

        $this->assertSame([], $data['groups']);
        $this->assertSame(0, $data['examined']);
    }

    public function test_grouping_never_reaches_another_workspace(): void
    {
        (new Tenant)->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $theirs = $this->worker('Theirs', ['hearing' => 'Impaired'], 2);

        $mine = $this->worker('Mine', ['hearing' => 'Impaired']);
        $this->doctor();

        $data = $this->postJson('/api/doctor/tpv/group-findings', ['ids' => [$mine->id, $theirs->id]])
            ->assertOk()->json('data');

        $this->assertSame(1, $data['examined'], 'only this workspace was read');
        $this->assertSame(['Mine'], array_column($data['groups'][0]['people'], 'name'));
    }

    public function test_a_side_the_doctor_does_not_serve_is_not_theirs_to_group(): void
    {
        $worker = $this->worker('Ramesh', ['hearing' => 'Impaired']);
        $doctor = $this->doctor();
        $doctor->doctorProfile->update(['modules' => ['purchase']]);

        $this->postJson('/api/doctor/tpv/group-findings', ['ids' => [$worker->id]])->assertNotFound();
    }
}
