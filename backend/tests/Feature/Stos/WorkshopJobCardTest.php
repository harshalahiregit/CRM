<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\MaintenanceJob;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Domains\Fleet\Services\MaintenanceService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-MAINT — the job card as a real document (T-29 … T-33).
 *
 * The workshop screen itemised parts and labour from the day it was built, and
 * the lines were summed into two totals and thrown away. These cover the tables
 * that now keep them, and the three rules that come with a card being auditable:
 * the totals are derived from the lines so they cannot disagree, a QC verdict is
 * richer than a boolean, and a critical failure is not cleared by accident.
 */
class WorkshopJobCardTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::COMPANY, 'name' => 'Co1', 'slug' => 'co1',
            'subdomain' => 'co1', 'status' => 'active',
        ])->save();
    }

    private function user(): User
    {
        return User::create([
            'tenant_id' => self::COMPANY, 'name' => 'Workshop', 'role' => 'staff',
            'email' => 'ws-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vehicle(array $over = []): Vehicle
    {
        $v = Vehicle::create(array_merge([
            'company_id'          => self::COMPANY,
            'registration_number' => 'MH12AB'.random_int(1000, 9999),
            'vehicle_type'        => 'reefer',
            'status'              => 'AVAILABLE',
            'compliance_status'   => 'compliant',
        ], $over));

        VehicleLiveStatus::create([
            'vehicle_id' => $v->id, 'company_id' => self::COMPANY,
            'latitude' => '19.07609500', 'longitude' => '72.87765800',
            'speed' => '0.00', 'ignition' => false, 'last_ping_at' => now()->subMinute(),
        ]);

        return $v;
    }

    private function service(): MaintenanceService
    {
        return app(MaintenanceService::class);
    }

    private function open(Vehicle $vehicle, array $data = []): MaintenanceJob
    {
        return $this->service()->open(self::COMPANY, array_merge([
            'vehicle_id' => $vehicle->id, 'complaint' => 'Brake judder under load',
        ], $data), $this->user()->id);
    }

    /* ── T-30: the lines are kept ───────────────────────────────── */

    public function test_parts_and_labour_lines_are_stored_against_the_card(): void
    {
        $job = $this->open($this->vehicle(), [
            'parts' => [
                ['part_name' => 'Brake disc', 'quantity' => 2, 'unit_cost' => 4200, 'supplier' => 'TVS', 'warranty_months' => 12],
                ['part_name' => 'Brake pad set', 'quantity' => 1, 'unit_cost' => 1800],
            ],
            'labour' => [
                ['labour_type' => 'Brake overhaul', 'hours' => 4, 'hourly_rate' => 450, 'technician' => 'R. Kadam'],
            ],
        ]);

        $this->assertCount(2, $job->parts);
        $this->assertCount(1, $job->labour);

        $disc = $job->parts->firstWhere('part_name', 'Brake disc');
        $this->assertSame('8400.00', $disc->line_cost);
        $this->assertSame('TVS', $disc->supplier);
        // Without this, a warranty claim six months later has nothing to stand on.
        $this->assertSame(12, $disc->warranty_months);
        $this->assertSame('R. Kadam', $job->labour->first()->technician);
    }

    public function test_the_totals_are_summed_from_the_lines(): void
    {
        $job = $this->open($this->vehicle(), [
            'parts'  => [['part_name' => 'Brake disc', 'quantity' => 2, 'unit_cost' => 4200]],
            'labour' => [['labour_type' => 'Overhaul', 'hours' => 4, 'hourly_rate' => 450]],
        ]);

        // The card and its own itemisation can never disagree, because one is
        // computed from the other rather than typed twice.
        $this->assertSame('8400.00', $job->parts_cost);
        $this->assertSame('1800.00', $job->labour_cost);
        $this->assertSame('10200.00', $job->total_cost);
    }

    public function test_re_sending_the_lines_replaces_them_rather_than_appending(): void
    {
        $vehicle = $this->vehicle();
        $job = $this->open($vehicle, [
            'parts' => [['part_name' => 'Brake disc', 'quantity' => 2, 'unit_cost' => 4200]],
        ]);

        $this->service()->update($job->id, self::COMPANY, [
            'parts' => [['part_name' => 'Brake disc', 'quantity' => 1, 'unit_cost' => 4200]],
        ], $this->user()->id);

        $job = $job->fresh();

        // Editing a card is the common case. Appending would double the bill
        // every time somebody corrected a quantity.
        $this->assertCount(1, $job->parts);
        $this->assertSame('4200.00', $job->parts_cost);
    }

    public function test_a_blank_starter_row_is_not_an_entry(): void
    {
        $job = $this->open($this->vehicle(), [
            'parts'  => [['part_name' => '', 'quantity' => 1, 'unit_cost' => 0]],
            'labour' => [['labour_type' => '   ', 'hours' => 0, 'hourly_rate' => 0]],
        ]);

        // The form opens with one empty row of each. Storing it would fill the
        // passport with nameless zero-cost lines.
        $this->assertCount(0, $job->parts);
        $this->assertCount(0, $job->labour);
        $this->assertSame('0.00', $job->total_cost);
    }

    public function test_a_card_settled_at_the_counter_with_one_figure_still_works(): void
    {
        $job = $this->open($this->vehicle(), ['parts_cost' => 5000, 'labour_cost' => 1000]);

        // Not every card is itemised, and refusing the un-itemised ones would
        // only push people to invent a fake line to get past the form.
        $this->assertSame('6000.00', $job->total_cost);
        $this->assertCount(0, $job->parts);
    }

    public function test_a_signed_total_still_overrides_the_itemisation(): void
    {
        $vehicle = $this->vehicle();
        $job = $this->open($vehicle);

        $result = $this->service()->close($job->id, self::COMPANY, [
            'parts'  => [['part_name' => 'Disc', 'quantity' => 2, 'unit_cost' => 4200]],
            'labour' => [['labour_type' => 'Overhaul', 'hours' => 4, 'hourly_rate' => 450]],
            // A warranty credit settled the card lower than the sum of its parts.
            'total_cost' => 5000,
        ], $this->user()->id);

        $this->assertSame('5000.00', $result['job']->total_cost);
        // The lines survive the override — that is what makes the discount visible.
        $this->assertSame('8400.00', $result['job']->parts_cost);
        $this->assertCount(1, $result['job']->parts()->get());
        $this->assertCount(1, $result['job']->labour()->get());
    }

    /* ── T-29 / T-32 / T-33 ─────────────────────────────────────── */

    public function test_the_workshop_that_did_the_work_is_recorded(): void
    {
        $job = $this->open($this->vehicle(), ['workshop_name' => 'Bhiwandi yard — Bay 3']);

        $this->assertSame('Bhiwandi yard — Bay 3', $job->workshop_name);
    }

    public function test_a_card_in_qc_still_holds_the_vehicle(): void
    {
        $vehicle = $this->vehicle();
        $this->open($vehicle, ['status' => 'qc']);

        // T-32 — the work is done but nobody has signed it off. This is exactly
        // the window in which somebody is tempted to take the vehicle.
        $this->assertSame('UNDER_MAINTENANCE', $vehicle->fresh()->status);
        $this->assertContains('qc', MaintenanceJob::OPEN_STATES);
        $this->assertContains('testing', MaintenanceJob::OPEN_STATES);
    }

    public function test_closing_a_card_records_how_long_the_vehicle_was_off_the_road(): void
    {
        $vehicle = $this->vehicle();
        $job = $this->open($vehicle);

        // Backdate the opening so there is a real interval to measure.
        $job->forceFill(['opened_at' => now()->subHours(30)])->save();

        $result = $this->service()->close($job->id, self::COMPANY, [], $this->user()->id);

        $this->assertSame('30.00', $result['job']->downtime_hours);

        $downtime = $this->service()->downtimeFor($vehicle->id, self::COMPANY);
        $this->assertSame('30.00', $downtime['total_hours']);
        $this->assertSame('1.25', $downtime['total_days']);
        $this->assertSame(1, $downtime['cards']);
    }

    public function test_an_open_card_contributes_no_downtime_yet(): void
    {
        $vehicle = $this->vehicle();
        $this->open($vehicle);

        // A running clock added to a historic total makes the total meaningless.
        $this->assertSame('0.00', $this->service()->downtimeFor($vehicle->id, self::COMPANY)['total_hours']);
    }

    /* ── T-31: the QC verdict ───────────────────────────────────── */

    public function test_a_pass_releases_the_vehicle_and_records_the_verdict(): void
    {
        $vehicle = $this->vehicle();
        $job = $this->open($vehicle);

        $result = $this->service()->close($job->id, self::COMPANY, [
            'qc_result' => 'PASS', 'road_tested' => true,
        ], $this->user()->id);

        $this->assertTrue($result['release']['released']);
        $this->assertSame('PASS', $result['job']->qc_result);
        $this->assertTrue((bool) $result['job']->road_tested);
        $this->assertSame('AVAILABLE', $vehicle->fresh()->status);
    }

    public function test_a_critical_fail_blocks_release_and_names_the_hold(): void
    {
        $vehicle = $this->vehicle();
        $job = $this->open($vehicle);

        $result = $this->service()->close($job->id, self::COMPANY, [
            'qc_result' => 'CRITICAL_FAIL',
        ], $this->user()->id);

        $this->assertFalse($result['release']['released']);
        $this->assertSame('UNDER_MAINTENANCE', $vehicle->fresh()->status);

        $codes = collect($result['release']['holds'])->pluck('code');
        $this->assertTrue($codes->contains('qc_critical_fail'));
    }

    public function test_the_old_boolean_still_works_and_never_means_critical(): void
    {
        $vehicle = $this->vehicle();
        $job = $this->open($vehicle);

        $result = $this->service()->close($job->id, self::COMPANY, ['qc_passed' => false], $this->user()->id);

        // Callers built against `qc_passed` are still in the field. The boolean
        // cannot express CRITICAL_FAIL, and guessing the severe reading would
        // strand vehicles nobody ever condemned.
        $this->assertSame('FAIL', $result['job']->qc_result);
        $this->assertFalse((bool) $result['job']->qc_passed);
        $this->assertFalse($result['release']['released']);
    }

    public function test_the_verdict_and_the_boolean_can_never_disagree(): void
    {
        $vehicle = $this->vehicle();
        $job = $this->open($vehicle);

        // A caller sending both, contradicting itself. The richer field wins and
        // the boolean is rewritten from it rather than stored as sent.
        $result = $this->service()->close($job->id, self::COMPANY, [
            'qc_result' => 'CRITICAL_FAIL', 'qc_passed' => true,
        ], $this->user()->id);

        $this->assertSame('CRITICAL_FAIL', $result['job']->qc_result);
        $this->assertFalse((bool) $result['job']->qc_passed);
    }

    /* ── T-31: a condemnation is not cleared by accident ────────── */

    public function test_an_unrelated_later_pass_does_not_un_condemn_the_vehicle(): void
    {
        $vehicle = $this->vehicle();

        $brakes = $this->open($vehicle, ['complaint' => 'Brake failure']);
        $this->service()->close($brakes->id, self::COMPANY, ['qc_result' => 'CRITICAL_FAIL'], $this->user()->id);

        // A routine oil change, closed clean, on the same vehicle.
        $oil = $this->open($vehicle, ['complaint' => 'Routine oil change']);
        $result = $this->service()->close($oil->id, self::COMPANY, ['qc_result' => 'PASS'], $this->user()->id);

        // This is the accident the explicit clearance exists to prevent: a
        // vehicle condemned on its brakes walking out of the yard because
        // somebody changed its oil.
        $this->assertFalse($result['release']['released']);
        $this->assertSame('UNDER_MAINTENANCE', $vehicle->fresh()->status);

        $hold = collect($result['release']['holds'])->firstWhere('code', 'qc_critical_fail_standing');
        $this->assertNotNull($hold);
        $this->assertStringContainsString($brakes->job_card_number, $hold['why']);
    }

    public function test_a_re_test_that_names_the_condemnation_clears_it(): void
    {
        $vehicle = $this->vehicle();

        $brakes = $this->open($vehicle, ['complaint' => 'Brake failure']);
        $this->service()->close($brakes->id, self::COMPANY, ['qc_result' => 'CRITICAL_FAIL'], $this->user()->id);

        $retest = $this->open($vehicle, ['complaint' => 'Brake rework and re-test']);
        $result = $this->service()->close($retest->id, self::COMPANY, [
            'qc_result' => 'PASS', 'clears_job_id' => $brakes->id,
        ], $this->user()->id);

        // Clearing is a deliberate act naming the card it answers, and it
        // leaves a record of who performed it.
        $this->assertTrue($result['release']['released']);
        $this->assertSame('AVAILABLE', $vehicle->fresh()->status);
        $this->assertSame($brakes->id, $result['job']->clears_job_id);
    }

    public function test_a_card_cannot_clear_a_condemnation_while_failing_qc_itself(): void
    {
        $vehicle = $this->vehicle();

        $brakes = $this->open($vehicle, ['complaint' => 'Brake failure']);
        $this->service()->close($brakes->id, self::COMPANY, ['qc_result' => 'CRITICAL_FAIL'], $this->user()->id);

        $retest = $this->open($vehicle);
        $result = $this->service()->close($retest->id, self::COMPANY, [
            'qc_result' => 'FAIL', 'clears_job_id' => $brakes->id,
        ], $this->user()->id);

        // Naming a card while failing QC yourself is meaningless, so it is
        // dropped rather than stored as a half-clearance.
        $this->assertFalse($result['release']['released']);
        $this->assertNull($result['job']->clears_job_id);
    }

    public function test_the_standing_condemnation_is_visible_to_the_screens(): void
    {
        $vehicle = $this->vehicle();

        $brakes = $this->open($vehicle, ['complaint' => 'Brake failure']);
        $this->service()->close($brakes->id, self::COMPANY, ['qc_result' => 'CRITICAL_FAIL'], $this->user()->id);

        $condemnation = $this->service()->condemnationFor($vehicle->id, self::COMPANY);

        // The workshop cannot answer a condemnation it cannot see.
        $this->assertNotNull($condemnation);
        $this->assertSame($brakes->job_card_number, $condemnation['job_card_number']);
    }

    public function test_a_clean_vehicle_has_no_condemnation(): void
    {
        $vehicle = $this->vehicle();
        $job = $this->open($vehicle);
        $this->service()->close($job->id, self::COMPANY, ['qc_result' => 'PASS'], $this->user()->id);

        $this->assertNull($this->service()->condemnationFor($vehicle->id, self::COMPANY));
    }

    /* ── The HTTP surface ───────────────────────────────────────── */

    public function test_the_api_accepts_itemised_lines_and_returns_the_verdict(): void
    {
        $vehicle = $this->vehicle();
        $user = $this->user();

        $job = $this->actingAs($user)->postJson('/api/v1/fleet/maintenance/job-cards', [
            'vehicle_id' => $vehicle->id,
            'complaint'  => 'Brake judder',
            'workshop_name' => 'Bhiwandi yard',
            'parts'  => [['part_name' => 'Brake disc', 'quantity' => 2, 'unit_cost' => 4200]],
            'labour' => [['labour_type' => 'Overhaul', 'hours' => 4, 'hourly_rate' => 450]],
        ])->assertCreated()->json('data');

        $this->assertSame('10200.00', $job['total_cost']);

        $response = $this->actingAs($user)
            ->putJson("/api/v1/fleet/maintenance/job-cards/{$job['id']}/close", [
                'qc_result' => 'CRITICAL_FAIL',
            ])->assertOk();

        $this->assertSame('CRITICAL_FAIL', $response->json('data.job.qc_result'));
        $this->assertFalse($response->json('data.release.released'));
    }

    public function test_an_unknown_qc_verdict_is_refused(): void
    {
        $vehicle = $this->vehicle();
        $user = $this->user();

        $job = $this->actingAs($user)->postJson('/api/v1/fleet/maintenance/job-cards', [
            'vehicle_id' => $vehicle->id, 'complaint' => 'Brake judder',
        ])->assertCreated()->json('data');

        // A free-text verdict would silently become "not PASS" and hold the
        // vehicle for a reason nobody can look up.
        $this->actingAs($user)
            ->putJson("/api/v1/fleet/maintenance/job-cards/{$job['id']}/close", ['qc_result' => 'PROBABLY_FINE'])
            ->assertStatus(422);
    }

    public function test_the_passport_shows_the_lines_and_the_downtime(): void
    {
        $vehicle = $this->vehicle();
        $user = $this->user();

        $job = $this->open($vehicle, [
            'parts' => [['part_name' => 'Brake disc', 'quantity' => 2, 'unit_cost' => 4200]],
        ]);
        $job->forceFill(['opened_at' => now()->subHours(12)])->save();
        $this->service()->close($job->id, self::COMPANY, ['qc_result' => 'PASS'], $user->id);

        $workshop = $this->actingAs($user)
            ->getJson("/api/v1/fleet/vehicles/{$vehicle->id}/passport")
            ->assertOk()->json('data.workshop');

        $this->assertSame('12.00', $workshop['downtime']['total_hours']);
        $this->assertCount(1, $workshop['jobs'][0]['parts']);
        $this->assertSame('Brake disc', $workshop['jobs'][0]['parts'][0]['part_name']);
    }

    public function test_another_companys_lines_are_never_visible(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Co2', 'slug' => 'co2', 'subdomain' => 'co2', 'status' => 'active',
        ])->save();

        $vehicle = $this->vehicle();
        $job = $this->open($vehicle, [
            'parts' => [['part_name' => 'Brake disc', 'quantity' => 2, 'unit_cost' => 4200]],
        ]);

        $outsider = User::create([
            'tenant_id' => 2, 'name' => 'Other', 'role' => 'staff',
            'email' => 'other-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->actingAs($outsider)
            ->getJson("/api/v1/fleet/vehicles/{$vehicle->id}/passport")
            ->assertStatus(404);

        $this->assertSame(0, \App\Domains\Fleet\Models\MaintenanceJobPart::forCompany(2)->count());
        $this->assertSame(1, \App\Domains\Fleet\Models\MaintenanceJobPart::forCompany(self::COMPANY)->count());
        $this->assertNotNull($job);
    }
}
