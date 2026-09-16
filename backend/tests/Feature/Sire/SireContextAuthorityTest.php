<?php

namespace Tests\Feature\Sire;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Sire\Models\Report;
use Tests\TestCase;

/**
 * The server decides where an issue was filed, not the browser.
 *
 * REPORT-ISSUE.md has always said so; the code did not do it. capture() wrote
 * the client's module, section and screen straight onto the row and
 * SireContextProvider::resolve() was never called from anywhere, so a hand-written
 * POST could file a bug against any module it fancied and the register would
 * believe it.
 */
class SireContextAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        Sanctum::actingAs($this->user);
    }

    private function file(array $context): Report
    {
        $id = $this->postJson('/api/sire/reports', [
            'title'       => 'Saving a lead fails with a 500',
            'description' => 'The spinner runs and then nothing happens at all.',
            'context'     => $context,
        ])->assertCreated()->json('data.report.id');

        return Report::findOrFail($id);
    }

    public function test_a_lying_client_does_not_choose_the_module(): void
    {
        $report = $this->file([
            'url'            => 'http://localhost:5173/app/sales/leads/10452',
            'module'         => 'payroll',
            'section'        => 'salaries',
            'screen'         => 'payslip',
            'context_source' => 'route',
        ]);

        $this->assertSame('sales', $report->module);
        $this->assertSame('leads', $report->section);
        $this->assertSame('lead-details', $report->screen);
    }

    public function test_the_path_fills_in_what_the_client_left_out(): void
    {
        $report = $this->file(['url' => 'http://localhost:5173/app/hr/employees/12']);

        $this->assertSame('hr', $report->module);
        $this->assertSame('employees', $report->section);
        $this->assertSame('employee-details', $report->screen);
        $this->assertSame('high', $report->context_confidence);
    }

    public function test_a_declared_context_is_kept_because_the_server_cannot_know_it(): void
    {
        // A wizard step lives in component state; no path describes it.
        $report = $this->file([
            'url'                => 'http://localhost:5173/app/sales/leads/10452',
            'module'             => 'sales',
            'section'            => 'leads',
            'screen'             => 'lead-import-wizard-step-3',
            // 'provider' is what collector.js sends for a declared screen.
            'context_source'     => 'provider',
            'context_confidence' => 'declared',
        ]);

        $this->assertSame('lead-import-wizard-step-3', $report->screen);
    }

    public function test_an_unmapped_route_still_files_the_report(): void
    {
        $report = $this->file(['url' => 'http://localhost:5173/app/nothing/like/this']);

        // Low confidence, nulls, and a report that exists. Never a blocked submit.
        $this->assertNotNull($report->id);
    }

    public function test_a_hand_corrected_context_survives_the_server(): void
    {
        // Level 3 offers the reporter a [Correct] button when detection is poor.
        // If the server overrode that, the button would silently do nothing.
        $report = $this->file([
            'url'            => 'http://localhost:5173/app/sales/leads/10452',
            'module'         => 'inventory',
            'section'        => 'vouchers',
            'screen'         => 'vouchers-list',
            'context_source' => 'user',
        ]);

        $this->assertSame('inventory', $report->module);
        $this->assertSame('vouchers-list', $report->screen);
    }
}
