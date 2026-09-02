<?php

namespace Tests\Feature\Admin;

use App\Models\Shared\Note;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Account, Activity and Notes tabs.
 *
 * None of this data is new. last_login_at, last_login_ip, user_sessions,
 * audit_logs and the shared notes table were all already written to; none of it
 * was reachable from the staff screen, so questions like "is this account still
 * being used" could only be answered from the database.
 */
class StaffRecordTabsTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'tabs-t', 'status' => 'active']);
    }

    private function user(string $role, string $email): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => ucfirst($role), 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
            'internal_role' => 'general',
        ]);
    }

    /* ── account ─────────────────────────────────────────────────────── */

    public function test_the_account_tab_reports_sign_in_facts(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $staff = $this->user('staff', 'priya@example.test');

        $staff->forceFill([
            'last_login_at' => now()->subDay(),
            'last_login_ip' => '203.0.113.9',
        ])->save();

        Sanctum::actingAs($admin);
        $this->getJson("/api/admin/staff/{$staff->id}/account")
            ->assertOk()
            ->assertJsonPath('data.last_login_ip', '203.0.113.9')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonStructure(['data' => ['last_login_at', 'created_at', 'sessions']]);
    }

    public function test_sessions_can_be_ended_without_deactivating_the_account(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $staff = $this->user('staff', 'priya@example.test');

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/staff/{$staff->id}/sessions/revoke")->assertOk();

        // The distinction that matters: signing a lost phone out is not the same
        // decision as locking somebody out of the company.
        $this->assertSame('active', $staff->fresh()->status);
    }

    /* ── activity ────────────────────────────────────────────────────── */

    /**
     * Both directions. "Who changed this person's permissions" is as interesting
     * as "what did this person change", and one tab has to answer both.
     */
    public function test_activity_shows_what_they_did_and_what_was_done_to_them(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $staff = $this->user('staff', 'priya@example.test');

        DB::table('audit_logs')->insert([
            [
                'tenant_id' => $this->tenant()->id, 'auditable_type' => 'App\\Models\\Sales\\SalesInvoice',
                'auditable_id' => 1, 'action' => 'created', 'actor_id' => $staff->id,
                'actor_name' => 'Priya', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->tenant()->id, 'auditable_type' => User::class,
                'auditable_id' => $staff->id, 'action' => 'permissions_changed', 'actor_id' => $admin->id,
                'actor_name' => 'Admin', 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        Sanctum::actingAs($admin);
        $rows = $this->getJson("/api/admin/staff/{$staff->id}/activity")->assertOk()->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame(['by_them', 'to_them'], collect($rows)->pluck('direction')->sort()->values()->all());
    }

    public function test_activity_does_not_leak_another_tenants_trail(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $staff = $this->user('staff', 'priya@example.test');

        $other = Tenant::create(['name' => 'O', 'slug' => 'tabs-o', 'status' => 'active']);
        DB::table('audit_logs')->insert([
            'tenant_id' => $other->id, 'auditable_type' => User::class, 'auditable_id' => $staff->id,
            'action' => 'from_elsewhere', 'actor_id' => $staff->id, 'actor_name' => 'X',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $this->getJson("/api/admin/staff/{$staff->id}/activity")->assertOk()->assertJsonCount(0, 'data');
    }

    /* ── notes ───────────────────────────────────────────────────────── */

    public function test_a_note_can_be_added_and_read_back(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $staff = $this->user('staff', 'priya@example.test');

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/staff/{$staff->id}/notes", [
            'title' => 'Handover', 'content' => 'Covering the Pune site until March.',
        ])->assertCreated();

        $this->getJson("/api/admin/staff/{$staff->id}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.content', 'Covering the Pune site until March.');
    }

    public function test_a_note_id_from_another_persons_record_does_not_resolve(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $a     = $this->user('staff', 'a@example.test');
        $b     = $this->user('staff', 'b@example.test');

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/staff/{$b->id}/notes", ['title' => 'About B'])->assertCreated();

        $note = Note::firstOrFail();

        // B's note, reached through A's record.
        $this->deleteJson("/api/admin/staff/{$a->id}/notes/{$note->id}")->assertStatus(404);
        $this->assertDatabaseHas('notes', ['id' => $note->id]);
    }

    /**
     * A title is required, because notes.title is NOT NULL while content is not
     * — the shared table treats the title as the note's identity, and five other
     * modules already keep that contract.
     */
    public function test_a_note_without_a_title_is_refused(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $staff = $this->user('staff', 'priya@example.test');

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/staff/{$staff->id}/notes", ['content' => 'No title.'])
            ->assertStatus(422);
    }

    /** Script in a note must not come back as script. */
    public function test_note_content_is_sanitised(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $staff = $this->user('staff', 'priya@example.test');

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/staff/{$staff->id}/notes", [
            'title' => 'Handover', 'content' => 'Fine <script>alert(1)</script> text',
        ])->assertCreated();

        $stored = (string) Note::firstOrFail()->content;

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringContainsString('Fine', $stored);
    }

    /**
     * Any admin can remove any note here, and that is the real rule: this whole
     * route group sits behind role:admin, so every caller is already an admin.
     */
    public function test_an_admin_can_remove_a_note_written_by_another_admin(): void
    {
        $one = $this->user('admin', 'one@example.test');
        $two = $this->user('admin', 'two@example.test');
        $staff = $this->user('staff', 'priya@example.test');

        Sanctum::actingAs($one);
        $this->postJson("/api/admin/staff/{$staff->id}/notes", ['title' => 'Mine'])->assertCreated();
        $note = Note::firstOrFail();

        Sanctum::actingAs($two);
        $this->deleteJson("/api/admin/staff/{$staff->id}/notes/{$note->id}")->assertOk();
        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    }

    public function test_these_tabs_are_not_open_to_anybody_logged_in(): void
    {
        $staff  = $this->user('staff', 'priya@example.test');
        $nobody = $this->user('staff', 'nobody@example.test');

        Sanctum::actingAs($nobody);
        $this->getJson("/api/admin/staff/{$staff->id}/account")->assertStatus(403);
        $this->getJson("/api/admin/staff/{$staff->id}/activity")->assertStatus(403);
        $this->getJson("/api/admin/staff/{$staff->id}/notes")->assertStatus(403);
    }
}
