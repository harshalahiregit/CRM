<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The advance form's option lists come from settings, not from the app.
 *
 * Ten advance types and four categories were Dart literals, so adding "Medical
 * Advance" meant rebuilding the app and getting every employee to update it.
 * That is the whole problem this work exists to remove.
 */
class AdvanceOptionsTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'adv-options', 'status' => 'active']);
        $this->tenantId = $tenant->id;

        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Ravi', 'email' => 'r@adv.test',
            'password' => Hash::make('x'), 'role' => 'staff', 'status' => 'active',
        ]);

        HrEmployee::create([
            'tenant_id' => $tenant->id, 'employee_code' => 'E1', 'name' => 'Ravi',
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2020-01-01', 'status' => 'Active', 'user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);
    }

    private function advanceOptions(): array
    {
        return $this->postJson('/api/Hrm/advance/options')->assertOk()->json('data');
    }

    public function test_the_defaults_are_served_when_nothing_is_configured(): void
    {
        $o = $this->advanceOptions();

        $this->assertNotEmpty($o['types']);
        $this->assertNotEmpty($o['categories']);
        $this->assertContains('site_cash', array_column($o['types'], 'value'));
        $this->assertContains('Site Cash Advance', array_column($o['types'], 'label'));
    }

    /** The point of the whole change: an admin adds a type, the phone sees it. */
    public function test_an_admin_can_add_a_type_without_an_app_update(): void
    {
        app(SettingsService::class)->set($this->tenantId, HrSetting::GROUP, 'advance_types',
            "salary|Salary Advance\nmedical|Medical Advance");

        $types = array_column($this->advanceOptions()['types'], 'value');

        $this->assertSame(['salary', 'medical'], $types);
    }

    /** A line with no pipe is its own value, so a quick list still works. */
    public function test_a_bare_label_is_its_own_value(): void
    {
        app(SettingsService::class)->set($this->tenantId, HrSetting::GROUP, 'advance_types', "Travel\nFuel");

        $o = $this->advanceOptions()['types'];

        $this->assertSame('Travel', $o[0]['value']);
        $this->assertSame('Travel', $o[0]['label']);
    }

    /**
     * An emptied setting falls back rather than serving nothing.
     *
     * An advance form with no types in the dropdown cannot be submitted at all,
     * so somebody clearing the box by accident must not stop everybody
     * requesting an advance.
     */
    public function test_clearing_the_setting_falls_back_to_the_defaults(): void
    {
        app(SettingsService::class)->set($this->tenantId, HrSetting::GROUP, 'advance_types', '');

        $this->assertNotEmpty($this->advanceOptions()['types']);
    }

    /** Blank lines and stray spaces do not become empty dropdown entries. */
    public function test_untidy_input_does_not_produce_empty_options(): void
    {
        app(SettingsService::class)->set($this->tenantId, HrSetting::GROUP, 'advance_types',
            "  salary|Salary Advance  \n\n\n   \nfuel|Fuel  ");

        $o = $this->advanceOptions()['types'];

        $this->assertCount(2, $o);
        $this->assertSame('salary', $o[0]['value']);
        $this->assertSame('Fuel', $o[1]['label']);
    }

    public function test_one_tenants_list_is_not_anothers(): void
    {
        app(SettingsService::class)->set($this->tenantId, HrSetting::GROUP, 'advance_types', 'mine|Mine');

        $other = Tenant::create(['name' => 'Other', 'slug' => 'adv-options-2', 'status' => 'active']);
        $theirUser = User::create([
            'tenant_id' => $other->id, 'name' => 'Them', 'email' => 't@adv.test',
            'password' => Hash::make('x'), 'role' => 'staff', 'status' => 'active',
        ]);
        HrEmployee::create([
            'tenant_id' => $other->id, 'employee_code' => 'E2', 'name' => 'Them',
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2020-01-01', 'status' => 'Active', 'user_id' => $theirUser->id,
        ]);

        Sanctum::actingAs($theirUser);

        $this->assertNotContains('mine', array_column($this->advanceOptions()['types'], 'value'));
    }
}
