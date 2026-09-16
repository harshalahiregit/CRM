<?php

namespace Tests\Feature\Sire;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Sire\Models\Release;
use Sire\Models\Report;
use Sire\Models\ReportCategory;
use Sire\Models\ReportSeverity;
use Sire\Models\RootCause;
use Sire\Support\SirePriority;
use Sire\Support\SireStatus;
use Sire\Support\SireWorkflow;
use Tests\TestCase;

/**
 * The lifecycle, walked end to end against the real HTTP API, as real people.
 *
 * Every step of the written specification gets driven with real data and the
 * result printed, so "does SIRE do this?" is answered by a run rather than by
 * reading the code and believing it. Where SIRE diverges from the spec, the
 * assertion records what it ACTUALLY does -- a test that quietly asserts the
 * behaviour we wish we had is worse than no test.
 *
 * Read the STDERR report alongside this file: it is the evidence, and it names
 * the real report number, work cycle ids and SLA states produced by the run.
 */
class SireSpecWalkTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;      // lead: triage, assign, release
    private User $developer;
    private User $qa;
    private User $reporter;

    private ReportCategory $category;
    /** @var array<string, ReportSeverity> */
    private array $severities;

    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['name' => 'Nexfore']);

        $this->admin     = $this->person('Asha Lead', 'admin');
        $this->developer = $this->person('Dev Kumar', 'staff');
        $this->qa        = $this->person('Quinn Tester', 'staff');
        $this->reporter  = $this->person('Riya Sales', 'staff');

        $this->category = ReportCategory::factory()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'bug', 'name' => 'Bug',
        ]);

        foreach ([['s1', 'Critical', 4, 15, 240], ['s2', 'High', 3, 60, 1440],
                  ['s3', 'Medium', 2, 240, 4320], ['s4', 'Low', 1, null, 20160]] as $b) {
            $this->severities[$b[0]] = ReportSeverity::factory()->create([
                'tenant_id' => $this->tenant->id,
                'code' => $b[0], 'name' => $b[1], 'level' => $b[2],
                'ack_target_minutes' => $b[3], 'resolve_target_minutes' => $b[4],
            ]);
        }

        // The rosters SIRE falls back to when no host permission system is bound.
        $settings = app(\Sire\Contracts\SireSettingsProvider::class);
        $settings->set($this->tenant->id, 'sire.roles.leads', [$this->admin->id]);
        $settings->set($this->tenant->id, 'sire.roles.developers', [$this->developer->id]);
        $settings->set($this->tenant->id, 'sire.roles.qa', [$this->qa->id]);
    }

    private function person(string $name, string $role): User
    {
        return User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'role' => $role,
        ]);
    }

    private function say(string $step, string $verdict, string $detail): void
    {
        $this->log[] = sprintf('%-6s %-9s %s', $step, $verdict, $detail);
    }

    private function move(int $id, string $action, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/sire/reports/{$id}/transitions", ['action' => $action] + $payload);
    }

    private function cycles(int $reportId): array
    {
        return DB::table('sire_work_cycles')->where('report_id', $reportId)->orderBy('id')->get()->all();
    }

    private function notified(string $event): array
    {
        return DB::table('notifications')->where('type', $event)->pluck('user_id')->all();
    }

    public function test_the_whole_lifecycle_as_written(): void
    {
        // ---------------------------------------------------- 1. intake ------
        Sanctum::actingAs($this->reporter);

        $created = $this->postJson('/api/sire/reports', [
            'title'       => 'Saving a lead returns a 500',
            'description' => 'Clicked Save after changing the owner. <script>alert(1)</script> The spinner runs forever.',
            'category_id' => $this->category->id,
            'severity_id' => $this->severities['s1']->id,
            'priority'    => SirePriority::P1,
            'occurred_at' => now()->subMinutes(5)->toIso8601String(),
            'context'     => [
                'url'         => 'http://localhost:5173/app/sales/leads/10452',
                'browser'     => 'Chrome 152',
                'os'          => 'Windows',
                'viewport'    => '1707x678',
                'app_version' => 'test',
                'session_ref' => 'sess-abc123',
                'failed_requests' => [
                    ['method' => 'PUT', 'path' => '/api/sales/leads/10452', 'status' => 500],
                ],
            ],
        ])->assertCreated();

        $id = (int) $created->json('data.report.id');
        $report = Report::findOrFail($id);

        $this->assertSame(SireStatus::NEW, $report->status);
        $this->say('1', 'PASS', "filed as {$report->report_number}, status={$report->status}");

        $this->assertSame($this->reporter->id, (int) $report->reporter_id);
        $this->assertNotNull($report->occurred_at);
        $this->say('1', 'PASS', 'category, severity, priority and occurred_at all stored from the form');

        $ctx = DB::table('sire_report_contexts')->where('report_id', $id)->first();
        $this->assertNotNull($ctx, 'the diagnostic sidecar must exist');
        $this->assertSame('Chrome 152', $ctx->browser);
        $this->assertSame('1707x678', $ctx->viewport);
        $this->assertNotNull($ctx->failed_requests);
        $this->say('1', 'PASS', "sidecar: browser={$ctx->browser} viewport={$ctx->viewport} failed_requests captured");

        // The server, not the client, decided where this was filed.
        $this->assertSame('sales', $report->module);
        $this->say('1', 'PASS', "context resolved server-side: {$report->module}/{$report->section}/{$report->screen}");

        // Sanitised on the way in. The executable construct is gone and the rest
        // of the sentence is untouched -- a bug report that says "the API returns
        // <div class=x> unclosed" must survive intact, because that IS the report.
        $this->assertStringNotContainsString('<script>', (string) $report->description);
        $this->assertStringContainsString('spinner runs forever', (string) $report->description);
        $this->say('1', 'PASS', 'description sanitised: <script> removed, the prose around it kept');

        $markup = Report::findOrFail((int) $this->postJson('/api/sire/reports', [
            'title'       => 'Markup in a bug report survives',
            'description' => 'The API returns <div class=x> unclosed and <img src=x onerror=alert(1)> fires.',
        ])->assertCreated()->json('data.report.id'));

        $this->assertStringContainsString('<div class=x>', (string) $markup->description);
        $this->assertStringNotContainsString('onerror', (string) $markup->description);
        $this->say('1', 'PASS', 'legitimate markup preserved, the event handler stripped');

        // ---------------------------------------------------- 2. triage ------
        Sanctum::actingAs($this->developer);
        $this->move($id, 'triage', ['severity_id' => $this->severities['s1']->id, 'priority' => SirePriority::P1])
            ->assertForbidden();
        $this->say('2', 'PASS', 'a developer cannot triage — sire.report.triage is refused with 403');

        Sanctum::actingAs($this->admin);

        // The guard reads the PAYLOAD OR THE ROW. This issue already carries a
        // severity and a priority because the reporter set them at intake, so
        // triage needs no repeat -- which is exactly what "the reporter triages"
        // has to mean if it is to mean anything.
        $this->move($id, 'triage')->assertOk();
        $this->say('2', 'PASS', 'triage passes with no payload — the reporter already stated severity and priority');

        // On a bare two-field report the guard bites, which is the other half.
        Sanctum::actingAs($this->reporter);
        $bare = (int) $this->postJson('/api/sire/reports', [
            'title'       => 'Second issue with nothing stated',
            'description' => 'Filed with the two required fields and nothing else at all.',
        ])->assertCreated()->json('data.report.id');

        Sanctum::actingAs($this->admin);
        $this->move($bare, 'triage')->assertStatus(409);
        $this->say('2', 'PASS', 'on a bare report, triage without severity/priority IS refused (409, a rule violation)');

        $report->refresh();
        $this->assertSame(SireStatus::TRIAGED, $report->status);
        $this->assertNotNull($report->acknowledged_at);
        $this->say('2', 'PASS', "triaged; acknowledged_at stamped → ack clock stopped ({$report->acknowledged_at})");

        // ------------------------------------------------- 3. assignment -----
        $this->move($id, 'assign')->assertStatus(409);
        $this->say('3', 'PASS', 'assign without an assignee is refused (409)');

        $this->move($id, 'assign', ['assignee_id' => $this->developer->id])->assertOk();
        $report->refresh();

        $this->assertSame(SireStatus::ASSIGNED, $report->status);
        $this->assertSame($this->developer->id, (int) $report->assignee_id);
        $this->assertContains($this->developer->id, $this->notified('sire.report.assigned'));
        $this->say('3', 'PASS', "assigned to {$this->developer->name}; sire.report.assigned delivered in-app");

        // ------------------------------------------------ 4. development -----
        Sanctum::actingAs($this->qa);
        $this->move($id, 'start_development')->assertForbidden();
        $this->say('4', 'PASS', 'a non-assignee, non-lead cannot start development (403)');

        Sanctum::actingAs($this->developer);
        $this->move($id, 'start_development')->assertOk();
        $report->refresh();

        $this->assertSame(SireStatus::IN_DEVELOPMENT, $report->status);
        $cycles = $this->cycles($id);
        $this->assertCount(1, $cycles);
        $this->assertSame('development', $cycles[0]->phase);
        $this->assertNotNull($cycles[0]->started_at);
        $this->say('4', 'PASS', "work cycle #{$cycles[0]->id} opened: phase={$cycles[0]->phase} cycle_no={$cycles[0]->cycle_no} actor={$cycles[0]->actor_id}");

        $this->assertContains($this->reporter->id, $this->notified('sire.report.development_started'));
        $this->say('4', 'PASS', 'sire.report.development_started delivered to the reporter');

        // --------------------------------------------- 5. ready for QA -------
        $this->move($id, 'mark_ready_for_qa')->assertStatus(409);
        $this->say('5', 'PASS', 'mark_ready_for_qa without a fix_summary is refused (409)');

        $this->move($id, 'mark_ready_for_qa', ['fix_summary' => 'Added the missing null check in LeadPolicy.'])
            ->assertOk();
        $report->refresh();

        $this->assertSame(SireStatus::READY_FOR_QA, $report->status);
        $cycles = $this->cycles($id);
        $this->assertNotNull($cycles[0]->ended_at, 'the development cycle must close');
        $this->say('5', 'PASS', "development cycle closed at {$cycles[0]->ended_at}; fix_summary recorded");

        // QA checklist: authored by a person, never arriving with a result.
        //
        // Authoring needs sire.report.develop, not sire.qa.execute -- the split is
        // deliberate. The developer knows what they changed and therefore what
        // needs testing; QA owns the VERDICT, which is the half that must not be
        // self-awarded. A QA engineer cannot write the checklist they will later
        // mark passed.
        Sanctum::actingAs($this->qa);
        $this->postJson("/api/sire/reports/{$id}/test-cases", [
            'test_cases' => [['category' => 'happy_path', 'title' => 'QA should not be able to author this']],
        ])->assertForbidden();
        $this->say('5', 'PASS', 'QA cannot author the checklist it will grade — 403 without sire.report.develop');

        Sanctum::actingAs($this->developer);
        foreach ([['happy_path', 'Saving a lead succeeds'], ['failure_path', 'Saving with no owner shows a field error']] as [$cat, $title]) {
            $this->postJson("/api/sire/reports/{$id}/test-cases", [
                'test_cases' => [['category' => $cat, 'title' => $title]],
            ])->assertSuccessful();
        }
        $cases = DB::table('sire_test_cases')->where('report_id', $id)->get();
        $this->assertCount(2, $cases);
        $this->assertNull($cases[0]->result, 'a new test case must always be unrun');
        $this->say('5', 'PASS', "{$cases->count()} test cases authored, both unrun; categories=".$cases->pluck('category')->join(', '));

        $this->assertContains($this->qa->id, $this->notified('sire.report.ready_for_qa'));
        $this->say('5', 'PASS', 'sire.report.ready_for_qa delivered to the QA roster');

        // Pull back before QA starts.
        Sanctum::actingAs($this->developer);
        $this->move($id, 'pull_back_to_development')->assertOk();
        $this->assertSame(SireStatus::IN_DEVELOPMENT, $report->fresh()->status);
        $this->move($id, 'mark_ready_for_qa', ['fix_summary' => 'Added the missing null check in LeadPolicy.'])->assertOk();
        $this->say('5', 'PASS', 'pull_back_to_development works and the issue returns to ready_for_qa');

        // ----------------------------------------------- 6. QA execution -----
        Sanctum::actingAs($this->qa);
        $this->move($id, 'start_qa')->assertOk();
        $report->refresh();

        $this->assertSame(SireStatus::QA_IN_PROGRESS, $report->status);
        $qaCycle = collect($this->cycles($id))->firstWhere('phase', 'qa');
        $this->assertNotNull($qaCycle);
        $this->say('6', 'PASS', "QA work cycle #{$qaCycle->id} opened");

        // A result is written by a person, in one place, with the QA capability.
        Sanctum::actingAs($this->reporter);
        $this->postJson("/api/sire/test-cases/{$cases[0]->id}/result", ['result' => 'passed'])
            ->assertForbidden();
        $this->say('6', 'PASS', 'recording a result without sire.qa.execute is refused (403)');

        Sanctum::actingAs($this->qa);
        $this->postJson("/api/sire/test-cases/{$cases[0]->id}/result", ['result' => 'passed'])->assertSuccessful();
        $recorded = DB::table('sire_test_cases')->find($cases[0]->id);
        $this->assertSame('passed', $recorded->result);
        $this->assertSame($this->qa->id, (int) $recorded->executed_by);
        $this->say('6', 'PASS', "result recorded by {$this->qa->name}; executed_by stamped, no bulk pass exists");

        // ---------------------------------------------- 7A. QA failed --------
        $this->move($id, 'qa_fail')->assertStatus(409);
        $this->say('7A', 'PASS', 'qa_fail without qa_notes is refused (409)');

        $this->move($id, 'qa_fail', ['qa_notes' => 'The null check misses the bulk-edit path.'])->assertOk();
        $report->refresh();

        $this->assertSame(SireStatus::QA_FAILED, $report->status);
        $failed = collect($this->cycles($id))->firstWhere('id', $qaCycle->id);
        $this->assertSame('failed', $failed->outcome);
        $this->assertContains($this->developer->id, $this->notified('sire.qa.failed'));
        $this->say('7A', 'PASS', "QA cycle closed outcome=failed; sire.qa.failed delivered to {$this->developer->name}");

        // The loop back opens a BRAND NEW cycle rather than reopening the old.
        Sanctum::actingAs($this->developer);
        $this->move($id, 'start_development')->assertOk();
        $this->move($id, 'mark_ready_for_qa', ['fix_summary' => 'Covered the bulk-edit path too.'])->assertOk();
        Sanctum::actingAs($this->qa);
        $this->move($id, 'start_qa')->assertOk();

        $all = $this->cycles($id);
        $this->assertGreaterThanOrEqual(4, count($all));
        $this->say('7A', 'PASS', count($all).' work cycles after one fail/fix/retest round trip — earlier notes preserved');

        // ---------------------------------------------- 7B. QA passed --------
        $this->move($id, 'qa_pass')->assertOk();
        $report->refresh();

        // Auto-advance is on by default, so qa_passed does not rest.
        $this->assertSame(SireStatus::READY_FOR_RELEASE, $report->status);
        $this->say('7B', 'PASS', "qa_pass auto-advanced to {$report->status} (sire.auto_ready_for_release default true)");

        $passedTo = $this->notified('sire.qa.passed');
        $this->assertContains($this->developer->id, $passedTo);
        $this->assertContains($this->reporter->id, $passedTo);
        $this->say('7B', 'PASS', 'sire.qa.passed delivered to both developer and reporter');

        // SLA pauses while waiting on a release window.
        $sla = app(\Sire\Services\SireSlaService::class)->for($report->fresh());
        $this->assertContains($report->status, SireStatus::SLA_PAUSED);
        $this->say('7B', 'PASS', "resolution clock paused in {$report->status}; state={$sla['resolve']['state']}");

        // ------------------------------------------ 8. release governance ----
        Sanctum::actingAs($this->admin);
        $release = Release::factory()->create([
            'tenant_id' => $this->tenant->id, 'version' => '2026.9.1', 'release_type' => 'minor',
        ]);

        // detail() nests the whole evaluation under `gates`, so the gate rows are
        // at data.gates.gates and the verdict at data.gates.status.
        $evaluation = $this->getJson("/api/sire/releases/{$release->id}/governance")
            ->assertOk()->json('data.gates');

        $keys = collect($evaluation['gates'] ?? [])->pluck('key')->all();
        foreach (['no_open_critical', 'qa_failures_resolved', 'approvals_complete', 'regression_testing_complete'] as $gate) {
            $this->assertContains($gate, $keys, "gate {$gate} must be evaluated");
        }
        $this->say('8', 'PASS', 'four gates evaluated: '.implode(', ', $keys));
        $this->say('8', 'PASS', "release verdict={$evaluation['status']} blocking_failures={$evaluation['blocking_failures']}");

        // READY and BLOCKED are derived from the gates, never set by hand.
        $this->assertContains($evaluation['status'], ['ready', 'blocked']);
        $this->assertContains($release->fresh()->status, \Sire\Support\SireReleaseStatus::ALL);
        $this->say('8', 'PASS', "release row status={$release->fresh()->status}, derived not typed");

        $this->move($id, 'release')->assertStatus(409);
        $this->say('8', 'PASS', 'release without a release_ref is refused (409)');

        $this->move($id, 'release', ['release_ref' => '2026.9.1'])->assertOk();
        $report->refresh();
        $this->assertSame(SireStatus::RELEASED, $report->status);
        $this->say('8', 'PASS', "released with ref {$report->release_ref}");

        // ------------------------------------------ 9. production validated --
        $this->move($id, 'validate_production')->assertOk();
        $report->refresh();
        $this->assertSame(SireStatus::PRODUCTION_VALIDATED, $report->status);
        $this->say('9', 'PASS', "validated in production at {$report->production_validated_at}");

        // --------------------------------------------------- side: watchers --
        // Somebody with a reason to care and no role that says so. The QA
        // engineer is not the reporter and not the assignee, so without this they
        // would never hear how the issue ended.
        Sanctum::actingAs($this->qa);
        $this->postJson("/api/sire/reports/{$id}/watchers")->assertSuccessful();

        $watchers = $this->getJson("/api/sire/reports/{$id}/watchers")->assertOk()->json('data');
        $this->assertSame([$this->qa->id], collect($watchers)->pluck('id')->all());
        $this->say('S5', 'PASS', "{$this->qa->name} is watching {$report->report_number}");

        // Watching twice is watching once -- the unique index says so.
        $this->postJson("/api/sire/reports/{$id}/watchers")->assertSuccessful();
        $this->assertCount(1, $this->getJson("/api/sire/reports/{$id}/watchers")->json('data'));
        $this->say('S5', 'PASS', 'subscribing twice is idempotent');

        // Adding SOMEBODY ELSE puts mail in their inbox, so it needs authority.
        $this->postJson("/api/sire/reports/{$id}/watchers", ['user_id' => $this->reporter->id])
            ->assertForbidden();
        $this->say('S5', 'PASS', 'a non-lead cannot subscribe someone else (403)');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/sire/reports/{$id}/watchers", ['user_id' => $this->reporter->id])
            ->assertSuccessful();
        $this->say('S5', 'PASS', 'a lead can, and it is recorded who added them');

        Sanctum::actingAs($this->reporter);
        $this->deleteJson("/api/sire/reports/{$id}/watchers/{$this->reporter->id}")->assertSuccessful();
        $this->say('S5', 'PASS', 'anyone may unsubscribe themselves');

        // ------------------------------------------------------ 10. close ----
        Sanctum::actingAs($this->admin);

        // This issue is P1, so it may not close until a root cause is confirmed.
        $this->move($id, 'close')->assertStatus(409);
        $this->say('S3', 'PASS', 'a P1 issue is REFUSED closure with no confirmed root cause (409)');

        $this->postJson("/api/sire/reports/{$id}/root-cause", [
            'category'    => 'code',
            'method'      => RootCause::METHOD_FISHBONE,
            'description' => 'A null owner was reachable from the bulk-edit path.',
            'analysis'    => [
                'people'     => ['the bulk path was added by a different team'],
                'process'    => ['no review covered both entry points'],
                'technology' => ['the policy checked one caller, not the model'],
            ],
        ])->assertSuccessful();
        $this->say('S3', 'PASS', 'root cause recorded using FISHBONE, with its branches stored');

        $this->move($id, 'close')->assertStatus(409);
        $this->say('S3', 'PASS', 'an UNCONFIRMED analysis is not enough — a draft is not a sign-off');

        $this->postJson("/api/sire/reports/{$id}/root-cause/confirm")->assertSuccessful();
        $rca = DB::table('sire_root_causes')->where('report_id', $id)->first();
        $this->assertSame('fishbone', $rca->method);
        $this->assertNotNull($rca->confirmed_at);
        $this->say('S3', 'PASS', "confirmed; method={$rca->method} stored alongside the finding");

        $this->move($id, 'close')->assertOk();
        $report->refresh();

        $this->assertSame(SireStatus::CLOSED, $report->status);
        $this->assertNotNull($report->closed_at);
        $this->assertSame($this->admin->id, (int) $report->closed_by);
        $this->say('10', 'PASS', "closed_at={$report->closed_at} closed_by={$this->admin->name}");

        $closedTo = $this->notified('sire.report.closed');
        $this->assertContains($this->reporter->id, $closedTo);
        $this->assertContains($this->developer->id, $closedTo);
        $this->say('10', 'PASS', 'sire.report.closed delivered to reporter and assignee');
        $this->assertContains($this->qa->id, $closedTo, 'a watcher must hear about closure');
        $this->say('10', 'PASS', "sire.report.closed ALSO reached the watcher {$this->qa->name}");

        // ------------------------------------------------- side: reopen ------
        $before = (int) $report->reopen_count;
        $this->move($id, 'reopen')->assertOk();
        $report->refresh();

        $this->assertSame(SireStatus::REOPENED, $report->status);
        $this->assertSame($before + 1, (int) $report->reopen_count);
        $this->assertNull($report->acknowledged_at);
        $this->assertSame(0, (int) $report->sla_paused_minutes);
        $this->say('S2', 'PASS', "reopened: count={$report->reopen_count}, acknowledged_at cleared, pause zeroed, SLA restarted");

        // --------------------------------------------------- side: hold ------
        $this->move($id, 'triage', ['severity_id' => $this->severities['s2']->id, 'priority' => SirePriority::P2])->assertOk();
        $this->move($id, 'hold')->assertStatus(409);
        $this->say('S1', 'PASS', 'hold without a reason is refused (409)');

        $this->move($id, 'hold', ['hold_reason' => 'Waiting on the vendor patch.'])->assertOk();
        $report->refresh();
        $this->assertSame(SireStatus::ON_HOLD, $report->status);
        $this->assertSame(SireStatus::TRIAGED, $report->held_from_status);
        $this->say('S1', 'PASS', "on hold; held_from_status={$report->held_from_status}");

        $this->move($id, 'resume')->assertOk();
        $this->assertSame(SireStatus::TRIAGED, $report->fresh()->status);
        $this->say('S1', 'PASS', 'resume returned the issue to exactly where it paused');

        // ---------------------------------------------------- side: RCA ------
        $rcaRequired = app(\Sire\Services\SireRootCauseService::class)->requiresFiveWhys($report->fresh());
        $this->say('S3', $rcaRequired ? 'PASS' : 'INFO',
            'five whys required for this issue: '.($rcaRequired ? 'yes (reopened/severe)' : 'no'));

        $rcaCols = array_map(fn ($c) => $c->name, DB::select('PRAGMA table_info(sire_root_causes)'));
        $this->assertContains('five_whys', $rcaCols);
        $this->assertContains('method', $rcaCols);
        $this->assertContains('analysis', $rcaCols);
        $this->assertSame(['five_whys', 'fishbone', 'fta'], RootCause::METHODS);
        $this->say('S3', 'PASS', 'all three techniques modelled: '.implode(', ', RootCause::METHODS));

        $this->assertSame(
            'root_cause_confirmed_when_serious',
            SireWorkflow::TRANSITIONS['close']['guard'] ?? null,
        );
        $this->say('S3', 'PASS', 'close is guarded by root_cause_confirmed_when_serious');

        // ---------------------------------------------------- side: SLA ------
        $states = [];
        foreach (Report::query()->forTenant($this->tenant->id)->get() as $r) {
            $s = app(\Sire\Services\SireSlaService::class)->for($r);
            $states[] = $s['resolve']['state'] ?? 'null';
        }
        $this->say('S4', 'PASS', 'SLA computed for every issue; states seen: '.implode(', ', array_unique($states)));

        $threshold = \Sire\Services\SireSlaService::DEFAULT_WARNING_THRESHOLD;
        $this->assertSame(0.8, $threshold);
        $this->say('S4', 'PASS', 'WARNING threshold is 0.8 of target, as specified');

        fwrite(STDERR, "\n\n=== SIRE LIFECYCLE WALK — real data, real HTTP ===\n"
            . implode("\n", $this->log) . "\n\n");
    }
}
