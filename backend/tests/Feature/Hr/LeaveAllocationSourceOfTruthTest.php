<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeaveType;
use App\Models\Tenant;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * How many days a leave type is worth, and who decides.
 *
 * The Yearly Limit on the Leave Types screen is the answer, and it now is the
 * only answer. Four HR settings used to be consulted first — and because
 * SettingsService::getGroup() fills every registered key from the registry
 * defaults, they were always present and always won. The `?? $type->yearly_limit`
 * written beside each of them could not fire, so editing Casual, Earned or
 * Unpaid on the Leave Types screen did nothing at all while editing Sick worked
 * normally.
 *
 * The test that matters most is the Earned Leave one: the seeded type says 15,
 * the removed setting said 12, and the old code shipped 12 to every workspace
 * without anybody changing a thing.
 */
class LeaveAllocationSourceOfTruthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'alloc', 'status' => 'active']);
        $this->other  = Tenant::create(['name' => 'Two', 'slug' => 'alloc2', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function type(string $name, string $category, float $limit, ?Tenant $t = null): HrLeaveType
    {
        return HrLeaveType::create([
            'tenant_id' => ($t ?: $this->tenant)->id,
            'name' => $name, 'code' => strtoupper(substr(md5($name.uniqid()), 0, 5)),
            'category' => $category, 'paid' => true, 'yearly_limit' => $limit,
            'carry_forward' => false, 'max_carry_forward' => 0,
            'requires_attachment' => false, 'requires_approval' => true,
            'is_active' => true,
        ]);
    }

    private function employee(?Tenant $t = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => ($t ?: $this->tenant)->id, 'name' => 'E'.substr(uniqid(), -5),
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active',
        ]);
    }

    /** Run the real command. */
    private function allocate(array $options = []): int
    {
        return $this->artisan('hr:allocate-leave', array_merge(['--commit' => true], $options))->run();
    }

    private function allocatedFor(HrEmployee $employee, HrLeaveType $type): ?float
    {
        $value = HrEmployeeLeaveBalance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->value('allocated');

        return $value === null ? null : (float) $value;
    }

    /* ── 1. the leave type decides ────────────────────────────────────── */

    public function test_the_types_yearly_limit_is_what_gets_allocated(): void
    {
        $employee = $this->employee();
        $type = $this->type('Casual Leave', 'Casual', 9);

        $this->allocate();

        $this->assertSame(9.0, $this->allocatedFor($employee, $type));
    }

    public function test_editing_the_yearly_limit_changes_what_is_allocated(): void
    {
        $first = $this->employee();
        $type  = $this->type('Casual Leave', 'Casual', 9);

        $this->allocate();
        $this->assertSame(9.0, $this->allocatedFor($first, $type));

        // HR edits the Leave Type. A new joiner must get the new figure —
        // before this fix the box was decorative for Casual.
        $type->update(['yearly_limit' => 18]);
        $second = $this->employee();

        $this->allocate();

        $this->assertSame(18.0, $this->allocatedFor($second, $type));
        // The first employee's existing balance is untouched.
        $this->assertSame(9.0, $this->allocatedFor($first, $type));
    }

    /* ── 2. the Earned Leave regression ───────────────────────────────── */

    public function test_earned_leave_allocates_fifteen_not_twelve(): void
    {
        $employee = $this->employee();

        // Exactly what hr:seed-masters creates.
        $earned = $this->type('Earned Leave', 'Earned', 15);

        $this->allocate();

        // The removed leave_paid_days setting defaulted to 12 and won, so a
        // workspace that had changed nothing still got 12 here.
        $this->assertSame(15.0, $this->allocatedFor($employee, $earned));
        $this->assertNotSame(12.0, $this->allocatedFor($employee, $earned));
    }

    /* ── 3. the legacy settings cannot reach back in ──────────────────── */

    public function test_stored_legacy_setting_rows_no_longer_override_anything(): void
    {
        $employee = $this->employee();
        $casual = $this->type('Casual Leave', 'Casual', 9);
        $earned = $this->type('Earned Leave', 'Earned', 15);
        $unpaid = $this->type('Unpaid Leave', 'Unpaid', 4);

        // A workspace upgraded from before the removal still has these rows in
        // tenant_settings. They are inert, not authoritative.
        $this->storeLegacySettings([
            'leave_casual_days'   => 99,
            'leave_paid_days'     => 98,
            'leave_unpaid_days'   => 97,
            'leave_comp_off_days' => 96,
        ]);

        $this->allocate();

        $this->assertSame(9.0, $this->allocatedFor($employee, $casual));
        $this->assertSame(15.0, $this->allocatedFor($employee, $earned));
        $this->assertSame(4.0, $this->allocatedFor($employee, $unpaid));
    }

    /** Write the old keys straight into storage, bypassing the registry. */
    private function storeLegacySettings(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('tenant_settings')->insert([
                'tenant_id' => $this->tenant->id, 'group' => HrSetting::GROUP,
                'key' => $key, 'value' => (string) $value,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /* ── 4 & 5. classification by category, never by name ─────────────── */

    public function test_compassionate_leave_is_not_treated_as_comp_off(): void
    {
        $employee = $this->employee();

        // The old branch matched str_contains(name, 'comp') and handed this
        // type leave_comp_off_days, which defaulted to 0 — so a company
        // offering five days of compassionate leave granted none.
        $compassionate = $this->type('Compassionate Leave', 'Restricted', 5);

        $this->allocate();

        $this->assertSame(5.0, $this->allocatedFor($employee, $compassionate));
        $this->assertNotSame(0.0, $this->allocatedFor($employee, $compassionate));
    }

    /**
     * Any name at all is just a name now.
     *
     * @dataProvider awkwardNames
     */
    public function test_a_types_name_never_decides_its_allocation(string $name): void
    {
        $employee = $this->employee();
        $type = $this->type($name, 'Restricted', 7);

        $this->allocate();

        $this->assertSame(7.0, $this->allocatedFor($employee, $type),
            "the name \"{$name}\" changed the allocation");
    }

    public static function awkwardNames(): array
    {
        return [
            ['Compassionate Leave'],
            ['Company Retreat'],
            ['Comp Off'],
            ['Complimentary Day'],
            ['Casual Leave'],
        ];
    }

    /**
     * A genuine comp-off type is allocated what it says it is worth.
     *
     * There is no Comp-Off entry in HrLeaveType::CATEGORIES — the vocabulary
     * is Casual, Sick, Earned, Maternity, Paternity, Restricted, Unpaid — so
     * comp-off has never been a category, and the display-name match was the
     * only classification it ever had. With every type reading its own
     * yearly_limit there is nothing left to classify, and a workspace running
     * comp-off configures it like anything else.
     */
    public function test_a_genuine_comp_off_type_gets_its_configured_days(): void
    {
        $employee = $this->employee();
        $compOff = $this->type('Comp Off', 'Restricted', 3);

        $this->allocate();

        $this->assertSame(3.0, $this->allocatedFor($employee, $compOff));
    }

    /* ── 6. tenant isolation ──────────────────────────────────────────── */

    public function test_one_workspaces_leave_type_does_not_reach_another(): void
    {
        $here = $this->employee();
        $there = $this->employee($this->other);

        $hereType  = $this->type('Casual Leave', 'Casual', 9);
        $thereType = $this->type('Casual Leave', 'Casual', 21, $this->other);

        $this->allocate();

        $this->assertSame(9.0, $this->allocatedFor($here, $hereType));
        $this->assertSame(21.0, $this->allocatedFor($there, $thereType));

        // And nobody received the other workspace's type at all.
        $this->assertNull($this->allocatedFor($here, $thereType));
        $this->assertNull($this->allocatedFor($there, $hereType));
    }

    /**
     * No balance may point at an employee from another workspace.
     *
     * The assertions above check what each employee received, which is not
     * enough on its own: the command loops per tenant, so an unscoped employee
     * query allocates one workspace's leave types to another workspace's
     * people and stamps the row with the WRONG tenant_id. Those rows are
     * invisible to a lookup keyed on the employee's own tenant, so the leak
     * hides behind tests that look correct.
     *
     * This asserts the join instead: every row's tenant must be its employee's
     * tenant.
     */
    public function test_no_balance_ever_crosses_the_tenant_boundary(): void
    {
        $this->employee();
        $this->employee($this->other);
        $this->type('Casual Leave', 'Casual', 9);
        $this->type('Earned Leave', 'Earned', 15, $this->other);

        $this->allocate();

        $crossed = DB::table('hr_employee_leave_balances as b')
            ->join('hr_employees as e', 'e.id', '=', 'b.employee_id')
            ->whereColumn('b.tenant_id', '!=', 'e.tenant_id')
            ->count();

        $this->assertSame(0, $crossed, 'a balance was written against another workspace\'s employee');

        // And each workspace got exactly its own type, once per employee.
        $this->assertSame(1, HrEmployeeLeaveBalance::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, HrEmployeeLeaveBalance::where('tenant_id', $this->other->id)->count());
    }

    public function test_restricting_to_one_tenant_leaves_the_other_alone(): void
    {
        $here = $this->employee();
        $there = $this->employee($this->other);
        $this->type('Casual Leave', 'Casual', 9);
        $thereType = $this->type('Casual Leave', 'Casual', 21, $this->other);

        $this->allocate(['--tenant' => $this->tenant->id]);

        $this->assertNull($this->allocatedFor($there, $thereType));
        $this->assertSame(0, HrEmployeeLeaveBalance::where('tenant_id', $this->other->id)->count());
    }

    /* ── 7. existing balances are never touched ───────────────────────── */

    public function test_an_existing_balance_is_left_exactly_as_it_was(): void
    {
        $employee = $this->employee();
        $type = $this->type('Casual Leave', 'Casual', 9);

        $this->allocate();
        $before = HrEmployeeLeaveBalance::where('employee_id', $employee->id)->firstOrFail();

        // Somebody has since taken leave, and the type's limit has changed.
        $before->update(['used' => 3, 'available_balance' => 6]);
        $type->update(['yearly_limit' => 30]);

        $this->allocate();

        $after = $before->fresh();
        $this->assertSame(9.0, (float) $after->allocated, 'an existing allocation was rewritten');
        $this->assertSame(3.0, (float) $after->used);
        $this->assertSame(6.0, (float) $after->available_balance);
        $this->assertSame(1, HrEmployeeLeaveBalance::where('employee_id', $employee->id)->count());
    }

    /* ── 8. dry run ───────────────────────────────────────────────────── */

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->employee();
        $this->type('Casual Leave', 'Casual', 9);

        $this->artisan('hr:allocate-leave')->run();

        $this->assertSame(0, HrEmployeeLeaveBalance::count());
        // Not even the policy the commit path creates.
        $this->assertSame(0, DB::table('hr_leave_policies')->count());
    }

    public function test_the_dry_run_reports_the_type_limit(): void
    {
        $this->employee();
        $this->type('Earned Leave', 'Earned', 15);

        $this->artisan('hr:allocate-leave')
            ->expectsOutputToContain('would allocate 15')
            ->run();
    }

    /* ── inactive types ───────────────────────────────────────────────── */

    public function test_an_inactive_type_is_not_allocated(): void
    {
        $employee = $this->employee();
        $type = $this->type('Casual Leave', 'Casual', 9);
        $type->update(['is_active' => false]);

        $this->allocate();

        $this->assertNull($this->allocatedFor($employee, $type));
    }

    /* ── 9 & 10. the settings are gone ────────────────────────────────── */

    public function test_the_four_legacy_settings_are_no_longer_configuration(): void
    {
        $removed = ['leave_paid_days', 'leave_casual_days', 'leave_unpaid_days', 'leave_comp_off_days'];

        foreach ($removed as $key) {
            $this->assertFalse(HrSetting::isKey($key), "{$key} is still a settings key");
            $this->assertNotContains($key, HrSetting::keys());
        }

        // Gone from the schema the settings screen renders from, so the
        // controls disappear without a frontend change.
        $this->assertArrayNotHasKey('Leave', HrSetting::schema());

        // And gone from the resolved payload, so nothing can read them back.
        $resolved = app(SettingsService::class)->getGroup($this->tenant->id, HrSetting::GROUP);
        foreach ($removed as $key) {
            $this->assertArrayNotHasKey($key, $resolved);
        }
    }

    public function test_the_hr_settings_endpoint_no_longer_offers_them(): void
    {
        $user = \App\Models\User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'HR', 'email' => uniqid().'@alloc.test',
            'password' => \Illuminate\Support\Facades\Hash::make('Password123!'),
            'role' => 'admin', 'status' => 'active',
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($user);
        $body = $this->getJson('/api/hr/settings')->assertOk()->getContent();

        foreach (['leave_paid_days', 'leave_casual_days', 'leave_unpaid_days', 'leave_comp_off_days'] as $key) {
            $this->assertStringNotContainsString($key, $body);
        }
    }

    public function test_no_hr_code_still_consumes_the_removed_settings(): void
    {
        // Behaviour above proves the outcome; this proves the cause. The only
        // remaining mentions are SangoeTrackAdminController's, which validate
        // a payload relayed to the EXTERNAL SangoeTrack API and never read our
        // tenant settings.
        $offenders = [];

        foreach (array_merge(
            glob(base_path('app/Services/Hr/*.php')),
            glob(base_path('app/Services/Hr/**/*.php')),
            glob(base_path('app/Console/Commands/Hr/*.php')),
            glob(base_path('app/Support/Hr/*.php')),
        ) as $file) {
            $src = (string) file_get_contents($file);

            foreach (['leave_paid_days', 'leave_casual_days', 'leave_unpaid_days', 'leave_comp_off_days'] as $key) {
                // The quoted key as code would reference it — comments in
                // AllocateLeaveBalances name them deliberately to explain the
                // removal, and must not count as a consumer.
                if (str_contains($src, "'".$key."'")) {
                    $offenders[] = basename($file).' → '.$key;
                }
            }
        }

        $this->assertSame([], $offenders, 'still consumed: '.implode(', ', $offenders));
    }
}
