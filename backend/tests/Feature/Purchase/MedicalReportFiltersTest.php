<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerMedical;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Medical\MedicalReportService;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The medical report answers the three questions it was asked to answer.
 *
 * The brief wanted statistics "filterable by project, employee, or vendor". It
 * shipped filterable by vendor and date only — project and employee were not
 * accepted by the endpoint and nothing in the report was grouped by either, so
 * the two most operational questions ("how is this site doing", "what is this
 * person's history") could not be asked at all.
 */
class MedicalReportFiltersTest extends TestCase
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
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name = 'Bolt Supplies'): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE,
            'portal_status' => 'active', 'email' => Str::random(6).'@t.local',
        ]);
    }

    private function worker(PurchaseVendor $v, string $name, ?string $project): PurchaseWorker
    {
        return PurchaseWorker::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $v->id,
            'full_name' => $name, 'worker_code' => 'W-'.Str::random(5), 'project' => $project,
        ]);
    }

    private function medical(PurchaseWorker $w, array $extra = []): PurchaseWorkerMedical
    {
        return PurchaseWorkerMedical::create(array_merge([
            'tenant_id' => self::TENANT,
            'purchase_worker_id' => $w->id,
            'purchase_vendor_id' => $w->purchase_vendor_id,
            'exam_date' => now()->subDays(3)->toDateString(),
            'fitness_status' => 'Fit',
            'health_score' => 8,
        ], $extra));
    }

    private function report(array $filters = []): array
    {
        return app(MedicalReportService::class)->build('purchase', self::TENANT, $filters);
    }

    /* ── By project ─────────────────────────────────────────────────────── */

    public function test_the_report_can_be_filtered_by_project(): void
    {
        $v = $this->vendor();
        $this->medical($this->worker($v, 'Ravi', 'Metro Line 3'));
        $this->medical($this->worker($v, 'Sunil', 'Airport Terminal'));

        $this->assertSame(2, $this->report()['totals']['examinations']);
        $this->assertSame(1, $this->report(['project' => 'Metro Line 3'])['totals']['examinations']);
    }

    public function test_the_report_groups_by_project(): void
    {
        $v = $this->vendor();
        $this->medical($this->worker($v, 'Ravi', 'Metro Line 3'));
        $this->medical($this->worker($v, 'Amit', 'Metro Line 3'));
        $this->medical($this->worker($v, 'Sunil', 'Airport Terminal'));

        $byProject = collect($this->report()['by_project'])->keyBy('project');

        $this->assertSame(2, $byProject['Metro Line 3']['examinations']);
        $this->assertSame(2, $byProject['Metro Line 3']['workers']);
        $this->assertSame(1, $byProject['Airport Terminal']['examinations']);
    }

    public function test_a_worker_with_no_project_is_shown_as_unassigned(): void
    {
        // Rather than vanishing from the project breakdown entirely, which would
        // make the numbers in it disagree with the headline total.
        $this->medical($this->worker($this->vendor(), 'Ravi', null));

        $projects = collect($this->report()['by_project'])->pluck('project');
        $this->assertContains('Unassigned', $projects->all());
    }

    /* ── By employee ────────────────────────────────────────────────────── */

    public function test_the_report_can_be_filtered_by_employee(): void
    {
        $v = $this->vendor();
        $ravi = $this->worker($v, 'Ravi', 'Metro Line 3');
        $this->medical($ravi);
        $this->medical($ravi, ['exam_date' => now()->subDay()->toDateString(), 'is_reexam' => true]);
        $this->medical($this->worker($v, 'Sunil', 'Metro Line 3'));

        $this->assertSame(3, $this->report()['totals']['examinations']);
        $this->assertSame(2, $this->report(['worker_id' => $ravi->id])['totals']['examinations']);
    }

    public function test_each_worker_gets_one_line_with_their_latest_result(): void
    {
        // This is what makes "filter by employee" possible in the UI at all —
        // the picker is built from these rows.
        $v = $this->vendor();
        $ravi = $this->worker($v, 'Ravi', 'Metro Line 3');
        $this->medical($ravi, ['exam_date' => now()->subDays(30)->toDateString(), 'fitness_status' => 'Unfit', 'health_score' => 4]);
        $this->medical($ravi, ['exam_date' => now()->subDay()->toDateString(), 'fitness_status' => 'Fit', 'health_score' => 9, 'is_reexam' => true]);

        $row = collect($this->report()['by_worker'])->firstWhere('worker_id', $ravi->id);

        $this->assertSame('Ravi', $row['worker']);
        $this->assertSame(2, $row['examinations']);
        $this->assertSame(1, $row['reexams']);
        // The LATEST result, not the first — a worker who was unfit and has since
        // passed a re-examination is fit.
        $this->assertSame('Fit', $row['fitness']);
        $this->assertEquals(6.5, $row['avg_score']);
        $this->assertEquals(9, $row['latest_score']);
    }

    /* ── The filters compose ────────────────────────────────────────────── */

    public function test_project_and_vendor_narrow_together(): void
    {
        $a = $this->vendor('Alpha');
        $b = $this->vendor('Bravo');
        $this->medical($this->worker($a, 'Ravi', 'Metro Line 3'));
        $this->medical($this->worker($b, 'Sunil', 'Metro Line 3'));
        $this->medical($this->worker($a, 'Amit', 'Airport Terminal'));

        $this->assertSame(1, $this->report(['vendor_id' => $a->id, 'project' => 'Metro Line 3'])['totals']['examinations']);
    }

    public function test_the_endpoint_accepts_the_new_filters(): void
    {
        // They were previously rejected by validation, so the UI could not send
        // them even though the data was there.
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/purchase/medical/report?project=Metro&worker_id=1')->assertOk();
        $this->getJson('/api/tpv/medical/report?project=Metro&worker_id=1')->assertOk();
    }

    public function test_the_filters_are_echoed_back_with_the_report(): void
    {
        // So the screen can show what it is currently showing, and an exported
        // sheet says which slice of the data it is.
        $report = $this->report(['project' => 'Metro Line 3', 'worker_id' => 7]);

        $this->assertSame('Metro Line 3', $report['filters']['project']);
        $this->assertSame(7, $report['filters']['worker_id']);
    }
}
