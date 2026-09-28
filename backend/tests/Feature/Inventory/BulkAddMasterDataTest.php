<?php

namespace Tests\Feature\Inventory;

use App\Models\Inventory\Attribute;
use App\Models\Inventory\Group;
use App\Models\Inventory\Type;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Master data arrives in lists, so it can be added as one.
 *
 * Commodity types come off a supplier's catalogue a page at a time and colours
 * off a swatch card forty at a time, and the only way in was a single-line form:
 * type a name, press the button, wait, repeat. That is the whole of the
 * complaint behind "bulk upload option while adding commodity, group, sub
 * group".
 *
 * One request for the whole list, not one per row — forty round trips on a
 * single-threaded server is the difference between an import and a coffee
 * break, and every other request queues behind them.
 *
 * The rules the import has to keep, all pinned below:
 *  - a name that already exists is SKIPPED, not an error, so re-pasting last
 *    week's list ends with the list somebody wanted rather than a refusal;
 *  - the count that comes back says what happened, so nothing is silently
 *    ignored either;
 *  - it is all-or-nothing, so a bad row twenty lines down cannot leave nineteen
 *    committed and the rest lost;
 *  - and it is still admin-only, exactly like adding one at a time.
 */
class BulkAddMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role = 'admin'): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'email' => $role.'@inv.test',
            'password' => Hash::make('x'), 'role' => $role, 'status' => 'active',
        ]);
    }

    public function test_a_pasted_list_is_added_in_one_request(): void
    {
        Sanctum::actingAs($this->user());

        $this->postJson('/api/inventory/settings/types/bulk', [
            'rows' => [
                ['name' => 'Galvanised sheet'],
                ['name' => 'Mild steel bar'],
                ['name' => 'Copper tube'],
            ],
        ])->assertOk()->assertJsonPath('data.created', 3);

        $this->assertSame(3, Type::forTenant(self::TENANT)->count());
    }

    /** Colours carry their hex across — the value column, not just a name. */
    public function test_a_colour_keeps_the_value_beside_its_name(): void
    {
        Sanctum::actingAs($this->user());

        $this->postJson('/api/inventory/settings/attributes/bulk', [
            'rows' => [
                ['name' => 'Brick red', 'kind' => 'color', 'value' => '#c0392b'],
                ['name' => 'Slate', 'kind' => 'color', 'value' => '#475569'],
            ],
        ])->assertOk()->assertJsonPath('data.created', 2);

        $this->assertSame('#c0392b', Attribute::forTenant(self::TENANT)->where('name', 'Brick red')->value('value'));
    }

    /**
     * Re-pasting a list adds what is missing and skips the rest.
     *
     * Somebody who imported forty colours last week and adds two to the bottom
     * of the same file should get two more, not forty errors.
     */
    public function test_names_already_there_are_skipped_not_refused(): void
    {
        Sanctum::actingAs($this->user());

        $rows = [['name' => 'Galvanised sheet'], ['name' => 'Mild steel bar']];

        $this->postJson('/api/inventory/settings/types/bulk', ['rows' => $rows])->assertOk();

        $again = $this->postJson('/api/inventory/settings/types/bulk', [
            'rows' => [...$rows, ['name' => 'Copper tube']],
        ])->assertOk();

        $again->assertJsonPath('data.created', 1)->assertJsonPath('data.skipped', 2);
        $this->assertSame(3, Type::forTenant(self::TENANT)->count(), 'nothing was duplicated');
    }

    /** Matching ignores case — "SLATE" and "Slate" are the same colour. */
    public function test_a_duplicate_is_recognised_whatever_its_case(): void
    {
        Sanctum::actingAs($this->user());

        $this->postJson('/api/inventory/settings/types/bulk', ['rows' => [['name' => 'Copper tube']]])->assertOk();

        $this->postJson('/api/inventory/settings/types/bulk', ['rows' => [['name' => 'COPPER TUBE']]])
            ->assertOk()->assertJsonPath('data.skipped', 1);

        $this->assertSame(1, Type::forTenant(self::TENANT)->count());
    }

    /**
     * The same word can be a colour and a style — a duplicate is only a
     * duplicate within its own kind.
     */
    public function test_the_same_name_may_exist_under_a_different_kind(): void
    {
        Sanctum::actingAs($this->user());

        $this->postJson('/api/inventory/settings/attributes/bulk', [
            'rows' => [['name' => 'Matte', 'kind' => 'color']],
        ])->assertOk();

        $this->postJson('/api/inventory/settings/attributes/bulk', [
            'rows' => [['name' => 'Matte', 'kind' => 'style']],
        ])->assertOk()->assertJsonPath('data.created', 1);

        $this->assertSame(2, Attribute::forTenant(self::TENANT)->where('name', 'Matte')->count());
    }

    /** Sub-groups import under the group that was chosen on screen. */
    public function test_sub_groups_import_under_their_group(): void
    {
        Sanctum::actingAs($this->user());

        $group = Group::create(['tenant_id' => self::TENANT, 'name' => 'Fasteners']);

        $this->postJson('/api/inventory/settings/subgroups/bulk', [
            'rows' => [
                ['name' => 'Bolts', 'group_id' => $group->id],
                ['name' => 'Washers', 'group_id' => $group->id],
            ],
        ])->assertOk()->assertJsonPath('data.created', 2);

        $this->assertSame(2, \App\Models\Inventory\Subgroup::forTenant(self::TENANT)
            ->where('group_id', $group->id)->count());
    }

    /** Adding master data stays an admin's job, in bulk exactly as one at a time. */
    public function test_staff_cannot_import(): void
    {
        Sanctum::actingAs($this->user('staff'));

        $this->postJson('/api/inventory/settings/types/bulk', ['rows' => [['name' => 'Copper tube']]])
            ->assertForbidden();

        $this->assertSame(0, Type::forTenant(self::TENANT)->count());
    }

    /** An empty list is refused rather than reported as a successful import of nothing. */
    public function test_an_empty_list_is_refused(): void
    {
        Sanctum::actingAs($this->user());

        $this->postJson('/api/inventory/settings/types/bulk', ['rows' => []])
            ->assertStatus(422);
    }
}
