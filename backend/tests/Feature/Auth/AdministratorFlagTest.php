<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Promoting and demoting administrators, following the old CRM.
 *
 * Perfex lets an admin tick "Administrator" on a staff member, but wraps it in
 * three guards (Staff_model.php:414-542): the flag is defaulted off and only
 * raised when is_admin(), you cannot demote yourself
 * (cant_remove_yourself_from_admin), and the founding admin cannot be demoted
 * (cant_remove_main_admin). Together those stop a workspace being left with
 * nobody in charge.
 *
 * Before this, the CRM had no way to create a second admin at all — the role was
 * hardcoded to staff and every query filtered admins out of the screen.
 */
class AdministratorFlagTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'adm-t', 'status' => 'active']);
    }

    private function user(string $role, string $email): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => ucfirst($role), 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
            'internal_role' => 'general',
        ]);
    }

    public function test_an_admin_can_promote_a_staff_member(): void
    {
        $founder = $this->user('admin', 'founder@example.test');
        $staff   = $this->user('staff', 'priya@example.test');

        Sanctum::actingAs($founder);
        $this->putJson("/api/admin/staff/{$staff->id}", [
            'name' => 'Priya', 'administrator' => true,
        ])->assertOk();

        $this->assertSame('admin', $staff->fresh()->role);
    }

    public function test_a_promotion_is_audited(): void
    {
        $founder = $this->user('admin', 'founder@example.test');
        $staff   = $this->user('staff', 'priya@example.test');

        Sanctum::actingAs($founder);
        $this->putJson("/api/admin/staff/{$staff->id}", ['name' => 'Priya', 'administrator' => true])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $staff->id,
            'action'       => 'Promoted to Administrator',
        ]);
    }

    /** cant_remove_yourself_from_admin. */
    public function test_an_admin_cannot_demote_themselves(): void
    {
        $founder = $this->user('admin', 'founder@example.test');
        $second  = $this->user('admin', 'second@example.test');

        Sanctum::actingAs($second);
        $this->putJson("/api/admin/staff/{$second->id}", [
            'name' => 'Second', 'administrator' => false,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'You cannot remove your own administrator access.']);

        $this->assertSame('admin', $second->fresh()->role);
    }

    /** cant_remove_main_admin — the founding admin stays. */
    public function test_the_founding_admin_cannot_be_demoted(): void
    {
        $founder = $this->user('admin', 'founder@example.test');
        $second  = $this->user('admin', 'second@example.test');

        Sanctum::actingAs($second);
        $this->putJson("/api/admin/staff/{$founder->id}", [
            'name' => 'Founder', 'administrator' => false,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'The founding administrator cannot be demoted.']);

        $this->assertSame('admin', $founder->fresh()->role);
    }

    /** A later admin may be demoted by another admin — that is the point. */
    public function test_a_later_admin_can_be_demoted_by_someone_else(): void
    {
        $founder = $this->user('admin', 'founder@example.test');
        $second  = $this->user('admin', 'second@example.test');

        Sanctum::actingAs($founder);
        $this->putJson("/api/admin/staff/{$second->id}", [
            'name' => 'Second', 'administrator' => false,
        ])->assertOk();

        $this->assertSame('staff', $second->fresh()->role);
    }

    /**
     * The field must never be trusted from the request. A staff member cannot
     * reach this endpoint at all (role:admin), so the guard below is the
     * belt-and-braces one: even inside, a non-admin actor is ignored.
     */
    public function test_the_administrator_field_is_ignored_for_a_non_admin_actor(): void
    {
        $founder = $this->user('admin', 'founder@example.test');
        $staff   = $this->user('staff', 'priya@example.test');

        Sanctum::actingAs($staff);
        $this->putJson("/api/admin/staff/{$staff->id}", [
            'name' => 'Priya', 'administrator' => true,
        ])->assertStatus(403);

        $this->assertSame('staff', $staff->fresh()->role);
    }

    public function test_creating_with_the_flag_makes_an_admin_only_for_an_admin_actor(): void
    {
        $founder = $this->user('admin', 'founder@example.test');
        Sanctum::actingAs($founder);

        $this->postJson('/api/admin/staff', [
            'name' => 'New Admin', 'email' => 'newadmin@example.test',
            'password' => 'Password123!', 'internal_role' => 'general',
            'status' => 'active', 'administrator' => true,
        ])->assertCreated();

        $this->assertSame('admin', User::where('email', 'newadmin@example.test')->first()->role);
    }

    public function test_creating_without_the_flag_makes_staff(): void
    {
        $founder = $this->user('admin', 'founder@example.test');
        Sanctum::actingAs($founder);

        $this->postJson('/api/admin/staff', [
            'name' => 'Plain', 'email' => 'plain@example.test',
            'password' => 'Password123!', 'internal_role' => 'general',
            'status' => 'active',
        ])->assertCreated();

        $this->assertSame('staff', User::where('email', 'plain@example.test')->first()->role);
    }

    /** Admins are now visible on the screen — "who are the admins" is answerable. */
    public function test_admins_appear_in_the_staff_list(): void
    {
        $founder = $this->user('admin', 'founder@example.test');
        $this->user('staff', 'priya@example.test');

        Sanctum::actingAs($founder);
        $emails = collect($this->getJson('/api/admin/staff')->assertOk()->json('data.staff'))->pluck('email');

        $this->assertContains('founder@example.test', $emails, 'Admins must be visible in the list.');
        $this->assertContains('priya@example.test', $emails);
    }
}
